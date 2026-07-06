<?php

declare(strict_types=1);

namespace Reflexometr\Services;

use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Support\Rand;

/**
 * Reads an r-test version's imported description (opaque JSON, D11) and, at run-start time,
 * compiles it into a concrete, resolved, single-use trial schedule for exactly one run. Only the
 * compiled schedule below is ever sent to the client — never the description's general parameter
 * ranges (min/max delay, etc.) themselves.
 *
 * Description shape (this project's chosen format — JSON, per D11 "format TBD, ServerTeam to
 * finalize"), generic across every r-test type so a new imported r-test needs no new server code:
 * {
 *   "trial_count": int > 0,
 *   "inter_stimulus_delay_ms": {"min": int >= 0, "max": int >= min},
 *   "response_channels": ["primary"] | ["left","right"] | ... (>=1 non-empty strings),
 *   "timeout_ms": int > 0 | null,      // null = no per-response timeout (channel must respond)
 *   "false_start_buffer": int >= 0 | omitted (defaults to DEFAULT_FALSE_START_BUFFER)
 * }
 *
 * Compiled schedule shape (sent to client + stored server-side for submission validation):
 * {
 *   "trial_count": int,               // the number of VALID trials the client must submit
 *   "response_channels": [...],
 *   "timeout_ms": int|null,
 *   "trials": [ { "index": int, "delay_ms": int }, ... ]  // trial_count + buffer_trials entries
 * }
 * `trials` deliberately contains more entries than `trial_count` (a "false-start buffer"): a
 * false start (input before the stimulus, CR-TEST-03 acceptance 3) doesn't count as a valid
 * trial, so the client discards it and re-runs that slot using the *next* pre-resolved buffer
 * entry, never inventing its own delay client-side (which would defeat D11). Once `trial_count`
 * valid trials are collected the client submits exactly that many, renumbered 0..trial_count-1 —
 * unused buffer entries are simply discarded. Because retries mean a submitted trial's position
 * no longer maps 1:1 to a specific `trials[]` entry, RunService's submission validation checks
 * inter-trial gaps against the *minimum* resolved delay in the whole compiled set rather than an
 * exact per-position match (see RunService::validateTrialLog) — still a genuine, if coarser,
 * anti-fabrication floor, consistent with D9's "best-effort, not cryptographic" scope.
 */
final class ScheduleCompiler
{
    public const DEFAULT_FALSE_START_BUFFER = 6;

    /** @param array<string,mixed> $description @throws ApiException */
    public static function validateDescription(array $description): void
    {
        $errors = [];

        $trialCount = $description['trial_count'] ?? null;
        if (!is_int($trialCount) || $trialCount < 1) {
            $errors[] = 'trial_count';
        }

        $delay = $description['inter_stimulus_delay_ms'] ?? null;
        if (
            !is_array($delay)
            || !isset($delay['min'], $delay['max'])
            || !is_int($delay['min']) || !is_int($delay['max'])
            || $delay['min'] < 0 || $delay['max'] < $delay['min']
        ) {
            $errors[] = 'inter_stimulus_delay_ms';
        }

        $channels = $description['response_channels'] ?? null;
        if (!is_array($channels) || count($channels) < 1 || array_filter($channels, static fn ($c) => !is_string($c) || $c === '') !== []) {
            $errors[] = 'response_channels';
        }

        if (array_key_exists('timeout_ms', $description)) {
            $timeout = $description['timeout_ms'];
            if ($timeout !== null && (!is_int($timeout) || $timeout < 1)) {
                $errors[] = 'timeout_ms';
            }
        }

        if (array_key_exists('false_start_buffer', $description)) {
            $buffer = $description['false_start_buffer'];
            if (!is_int($buffer) || $buffer < 0) {
                $errors[] = 'false_start_buffer';
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => $errors]);
        }
    }

    /**
     * @param array<string,mixed> $description Decoded, already-validated description JSON.
     * @return array<string,mixed> Compiled schedule.
     */
    public static function compile(array $description): array
    {
        self::validateDescription($description);

        $trialCount = (int) $description['trial_count'];
        $bufferTrials = (int) ($description['false_start_buffer'] ?? self::DEFAULT_FALSE_START_BUFFER);
        $min = (int) $description['inter_stimulus_delay_ms']['min'];
        $max = (int) $description['inter_stimulus_delay_ms']['max'];

        $total = $trialCount + $bufferTrials;
        $trials = [];
        for ($i = 0; $i < $total; $i++) {
            $trials[] = ['index' => $i, 'delay_ms' => Rand::intBetween($min, $max)];
        }

        return [
            'trial_count' => $trialCount,
            'buffer_trials' => $bufferTrials,
            'response_channels' => array_values($description['response_channels']),
            'timeout_ms' => $description['timeout_ms'] ?? null,
            'trials' => $trials,
        ];
    }
}
