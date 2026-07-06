<?php

declare(strict_types=1);

namespace Reflexometr\Services;

use PDO;
use Reflexometr\Config;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Repositories\ResultRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Repositories\RunTokenRepository;
use Reflexometr\Support\Clock;
use Reflexometr\Support\Rand;

/**
 * CR-TEST-02 (run-token issuance + submission), CR-TEST-06 (series mode), D9 (injection
 * prevention only — no statistical/outlier filtering), D11 (compiled schedule, never the raw
 * description).
 */
final class RunService
{
    private const MIN_HUMAN_REACTION_MS = 50; // physically-implausible-if-faster floor, per trial
    private const DEFAULT_RESPONSE_ALLOWANCE_MS = 3000; // used to size token TTL when no timeout_ms
    private const TTL_BUFFER_MS = 30_000;
    private const STIMULUS_TIMING_TOLERANCE_MS = 150; // timer-imprecision slack, not a security hole:
    // it only makes the check more lenient in the direction of *accepting* legitimate jitter, never
    // more lenient in the direction of allowing a stimulus to be anticipated earlier than scheduled.

    private const SERIES_MODE_PATTERN = '/^(single|until-quit|count:[1-9][0-9]*)$/';

    private PDO $db;
    private RTestRepository $rTests;
    private RTestVersionRepository $versions;
    private RunTokenRepository $tokens;
    private ResultRepository $results;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->rTests = new RTestRepository($this->db);
        $this->versions = new RTestVersionRepository($this->db);
        $this->tokens = new RunTokenRepository($this->db);
        $this->results = new ResultRepository($this->db);
    }

    /**
     * @return array<string,mixed> { token, expires_at_ms, r_test_id, r_test_version_id, version,
     *                                schedule }
     */
    public function startRun(int $userId, string $slug, ?int $requestedVersion, string $mode, ?string $seriesId): array
    {
        if (!preg_match(self::SERIES_MODE_PATTERN, $mode)) {
            throw new ApiException(ErrorCode::SERIES_MODE_INVALID, 400, ['fields' => ['mode']]);
        }

        $rTest = $this->rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }

        $version = $requestedVersion !== null
            ? $this->versions->findByTestAndVersion((int) $rTest['id'], $requestedVersion)
            : $this->versions->findActiveForTest((int) $rTest['id']);
        if ($version === null) {
            throw new ApiException(ErrorCode::RTEST_VERSION_NOT_FOUND, 404);
        }

        $description = json_decode((string) $version['description'], true);
        if (!is_array($description)) {
            throw new ApiException(ErrorCode::INTERNAL_ERROR, 500);
        }
        $schedule = ScheduleCompiler::compile($description);

        $issuedAtMs = Clock::nowMs();
        $responseAllowance = $schedule['timeout_ms'] ?? self::DEFAULT_RESPONSE_ALLOWANCE_MS;
        $sumDelays = array_sum(array_column($schedule['trials'], 'delay_ms'));
        $ttlMs = max(
            Config::getInt('RUN_TOKEN_TTL_SECONDS', 300) * 1000,
            $sumDelays + $schedule['trial_count'] * $responseAllowance + self::TTL_BUFFER_MS,
        );
        $expiresAtMs = $issuedAtMs + $ttlMs;

        $token = Rand::token();
        $tokenId = $this->tokens->create(
            $token,
            $userId,
            (int) $rTest['id'],
            (int) $version['id'],
            $mode,
            $seriesId,
            json_encode($schedule, JSON_THROW_ON_ERROR),
            $issuedAtMs,
            $expiresAtMs,
        );

        return [
            'token' => $token,
            'token_id' => $tokenId,
            'expires_at_ms' => $expiresAtMs,
            'r_test_id' => (int) $rTest['id'],
            'r_test_version_id' => (int) $version['id'],
            'version' => (int) $version['version'],
            'schedule' => $schedule,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $submittedTrials
     * @return array<string,mixed> { result_id, summary, primary_metric_ms }
     */
    public function submitRun(
        string $token,
        int $userId,
        array $submittedTrials,
        ?int $clientStartedAtMs,
        ?string $dominantHandAtSubmission,
    ): array {
        $runToken = $this->tokens->findByToken($token);
        if ($runToken === null || (int) $runToken['user_id'] !== $userId) {
            // Same error for "doesn't exist" and "belongs to someone else" — never leak which.
            throw new ApiException(ErrorCode::RUN_TOKEN_INVALID, 400);
        }

        $nowMs = Clock::nowMs();
        if ($nowMs > (int) $runToken['expires_at_ms']) {
            throw new ApiException(ErrorCode::RUN_TOKEN_EXPIRED, 410);
        }
        if ((int) $runToken['used'] === 1) {
            throw new ApiException(ErrorCode::RUN_TOKEN_ALREADY_USED, 409);
        }

        $schedule = json_decode((string) $runToken['schedule_json'], true);
        $this->validateTrialLog($schedule, $submittedTrials, (int) $runToken['issued_at_ms'], $nowMs);

        $this->db->beginTransaction();
        try {
            if (!$this->tokens->markUsedIfUnused((int) $runToken['id'], $nowMs)) {
                // Lost a race against a concurrent submission for the same token — no replay.
                $this->db->rollBack();
                throw new ApiException(ErrorCode::RUN_TOKEN_ALREADY_USED, 409);
            }

            $dominantHand = $dominantHandAtSubmission;
            if ($dominantHand !== null && !in_array($dominantHand, ['left', 'right', 'none-recorded'], true)) {
                $dominantHand = null;
            }

            ['summary' => $summary, 'primaryMetricMs' => $primaryMetricMs] =
                ResultSummaryService::compute($schedule, $submittedTrials, $dominantHand);

            $resultId = $this->results->create(
                $userId,
                (int) $runToken['r_test_id'],
                (int) $runToken['r_test_version_id'],
                (int) $runToken['id'],
                $dominantHand,
                (int) $schedule['trial_count'],
                json_encode($submittedTrials, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $primaryMetricMs,
                $clientStartedAtMs ?? (int) $runToken['issued_at_ms'],
                $nowMs,
            );

            $this->db->commit();
        } catch (ApiException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return [
            'result_id' => $resultId,
            'r_test_id' => (int) $runToken['r_test_id'],
            'r_test_version_id' => (int) $runToken['r_test_version_id'],
            'summary' => $summary,
            'primary_metric_ms' => $primaryMetricMs,
        ];
    }

    /**
     * Structural, injection-prevention-only validation (D9) — no statistical/outlier filtering.
     * @param array<string,mixed> $schedule
     * @param array<int,array<string,mixed>> $trials
     * @throws ApiException
     */
    private function validateTrialLog(array $schedule, array $trials, int $issuedAtMs, int $nowMs): void
    {
        $expectedCount = (int) $schedule['trial_count'];
        $channels = $schedule['response_channels'];
        $timeoutMs = $schedule['timeout_ms'] ?? null;

        if (!array_is_list($trials) || count($trials) !== $expectedCount) {
            throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'TRIAL_COUNT_MISMATCH']);
        }

        // The compiled schedule over-provisions resolved delays (trial_count + a false-start
        // buffer, see ScheduleCompiler) so a submitted trial's position no longer maps 1:1 to one
        // specific `trials[]` entry once retries happen. So the per-gap check below uses the
        // *minimum* resolved delay anywhere in the compiled set as a floor, rather than an exact
        // per-position match — coarser, but still a genuine floor (D9: best-effort, not
        // cryptographic).
        $minDelayMs = min(array_column($schedule['trials'], 'delay_ms'));

        $previousStimulusAt = null;
        foreach ($trials as $i => $trial) {
            if (
                !is_array($trial)
                || !array_key_exists('index', $trial)
                || !array_key_exists('stimulus_at', $trial)
                || !array_key_exists('responses', $trial)
                || !is_numeric($trial['stimulus_at'])
                || !is_array($trial['responses'])
            ) {
                throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'MALFORMED_TRIAL', 'index' => $i]);
            }
            if ((int) $trial['index'] !== $i) {
                throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'INDEX_OUT_OF_ORDER', 'index' => $i]);
            }

            $stimulusAt = (float) $trial['stimulus_at'];
            if ($previousStimulusAt !== null) {
                $actualGap = $stimulusAt - $previousStimulusAt;
                if ($actualGap < $minDelayMs - self::STIMULUS_TIMING_TOLERANCE_MS) {
                    throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'NON_MONOTONIC_TIMESTAMPS', 'index' => $i]);
                }
            }
            $previousStimulusAt = $stimulusAt;

            $responses = $trial['responses'];
            if (array_keys($responses) !== $channels) {
                // Order-insensitive check of the same key set (declared channels exactly).
                if (count($responses) !== count($channels) || array_diff($channels, array_keys($responses)) !== []) {
                    throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'CHANNEL_MISMATCH', 'index' => $i]);
                }
            }

            foreach ($channels as $channel) {
                $value = $responses[$channel];
                if ($value === null) {
                    if ($timeoutMs === null) {
                        // No timeout configured means every channel must respond.
                        throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'MISSING_RESPONSE', 'index' => $i]);
                    }
                    continue;
                }
                if (!is_numeric($value)) {
                    throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'MALFORMED_RESPONSE', 'index' => $i]);
                }
                $reactionAt = (float) $value;
                if ($reactionAt < $stimulusAt) {
                    throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'REACTION_BEFORE_STIMULUS', 'index' => $i]);
                }
                if ($timeoutMs !== null && ($reactionAt - $stimulusAt) > $timeoutMs + self::STIMULUS_TIMING_TOLERANCE_MS) {
                    throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'RESPONSE_AFTER_TIMEOUT', 'index' => $i]);
                }
            }
        }

        // Wall-clock consistency: reject instantaneous/fabricated bulk submissions. The server
        // clock (issued_at_ms -> now) is authoritative here, not client-reported timestamps. Floor
        // uses trial_count (not the buffer-inclusive total) times the schedule's own minimum
        // resolved delay — the lowest amount of real elapsed time any legitimate zero-false-start
        // run could possibly have taken.
        $minPlausibleMs = $expectedCount * $minDelayMs + $expectedCount * self::MIN_HUMAN_REACTION_MS;
        if (($nowMs - $issuedAtMs) < $minPlausibleMs) {
            throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'WALLCLOCK_TOO_FAST']);
        }
    }
}
