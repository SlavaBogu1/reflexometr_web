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
 * CR-TEST-23 (Sprint 11, Circle Collision Simple) adds three optional fields, all resolved into
 * the compiled schedule exactly like inter_stimulus_delay_ms already was — concrete per-trial
 * numbers only, never the raw min/max range itself (D11):
 * {
 *   "motion_duration_ms": {"min": int > 0, "max": int >= min}, // resolved per-trial -> duration_ms
 *   "circle_radius_px": int > 0,       // constant for this simple variant; resolved schedule-level
 *   "allow_early_response": bool       // true = skip REACTION_BEFORE_STIMULUS for every trial here
 * }
 * A coincidence-anticipation test (no discrete stimulus onset — the user watches continuous motion
 * and clicks when they judge it reaches some point) sets allow_early_response: true so a response
 * any time after motion starts, including well before the visual "collision," is valid data, not a
 * rejected false start (RunService::validateTrialLog). motion_duration_ms/circle_radius_px are
 * omitted entirely for a classic discrete-stimulus test (simple-reaction, two-hand-reaction) — the
 * compiled schedule simply doesn't carry those keys when absent from the description.
 *
 * Compiled schedule shape (sent to client + stored server-side for submission validation):
 * {
 *   "trial_count": int,               // the number of VALID trials the client must submit
 *   "response_channels": [...],
 *   "timeout_ms": int|null,
 *   "allow_early_response": bool,     // CR-TEST-23, only present when the description set it
 *   "circle_radius_px": int,          // CR-TEST-23, only present when the description set it
 *   "trials": [ { "index": int, "delay_ms": int, "motion_duration_ms"?: int }, ... ]
 *     // trial_count + buffer_trials entries; motion_duration_ms per-trial when CR-TEST-23 fields
 *     // are present (same per-trial-resolved pattern as delay_ms — a fresh random draw per trial,
 *     // not one fixed value reused for the whole schedule, so a client can't learn the value from
 *     // trial 1 and anticipate the rest).
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

    /** CR-TEST-27: sane upper bound on an imported description's trial_count — comfortably above
     * any real r-test's actual value (the largest seeded test uses 10) while ruling out a
     * pathological import (e.g. trial_count: 1000000) that would otherwise reach the
     * trial-generation loop in compile() and exhaust memory/time. Enforced at import-validation
     * time here; MAX_TRIAL_GENERATION_ITERATIONS below is the defense-in-depth backstop inside the
     * loop itself, in case this check is ever bypassed by a future code path. */
    public const MAX_TRIAL_COUNT = 500;

    /** CR-TEST-27: hard ceiling on trial-generation loop iterations (trial_count + buffer_trials),
     * independent of MAX_TRIAL_COUNT's import-time check — never let an unvalidated/bypassed
     * trial_count reach an uncaught PHP Fatal (e.g. exhausting memory) here. Comfortably above
     * MAX_TRIAL_COUNT + any sane false_start_buffer. */
    private const MAX_TRIAL_GENERATION_ITERATIONS = 2000;

    /** @param array<string,mixed> $description @throws ApiException */
    public static function validateDescription(array $description): void
    {
        $errors = [];

        $trialCount = $description['trial_count'] ?? null;
        if (!is_int($trialCount) || $trialCount < 1 || $trialCount > self::MAX_TRIAL_COUNT) {
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

        // CR-TEST-23 (Circle Collision Simple): motion_duration_ms/circle_radius_px are optional,
        // but mutually exclusive with CR-TEST-24's motion_speed_profile — a description carries
        // exactly one motion-timing shape (or neither, for a classic discrete-stimulus test).
        if (array_key_exists('motion_duration_ms', $description) && array_key_exists('motion_speed_profile', $description)) {
            $errors[] = 'motion_duration_ms';
            $errors[] = 'motion_speed_profile';
        } elseif (array_key_exists('motion_duration_ms', $description)) {
            if (!self::isValidIntRange($description['motion_duration_ms'])) {
                $errors[] = 'motion_duration_ms';
            }
        } elseif (array_key_exists('motion_speed_profile', $description)) {
            if (!self::isValidSpeedProfile($description['motion_speed_profile'])) {
                $errors[] = 'motion_speed_profile';
            }
        }

        if (array_key_exists('circle_radius_px', $description)) {
            $radius = $description['circle_radius_px'];
            if (!is_int($radius) || $radius < 1) {
                $errors[] = 'circle_radius_px';
            }
        }

        if (array_key_exists('allow_early_response', $description)) {
            if (!is_bool($description['allow_early_response'])) {
                $errors[] = 'allow_early_response';
            }
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => array_values(array_unique($errors))]);
        }
    }

    /**
     * CR-TEST-24 (Circle Collision Complex): { "start_speed_px_per_s": {min,max}, "mid_speed_px_per_s": {min,max},
     * "end_speed_px_per_s": {min,max}, "travel_distance_px": int > 0 } — three resolved waypoint
     * speeds the client linearly interpolates between (start -> mid at the trial's halfway point,
     * mid -> end at completion) over however long it takes to cover travel_distance_px at those
     * speeds. All three ranges must have strictly positive mins (a resolved 0 px/s waypoint would
     * mean the circles stop moving entirely, breaking the "continuous motion" premise).
     */
    private static function isValidSpeedProfile(mixed $profile): bool
    {
        if (!is_array($profile) || !isset($profile['travel_distance_px']) || !is_int($profile['travel_distance_px']) || $profile['travel_distance_px'] < 1) {
            return false;
        }
        foreach (['start_speed_px_per_s', 'mid_speed_px_per_s', 'end_speed_px_per_s'] as $key) {
            if (!self::isValidIntRange($profile[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** `{ "min": int >= $minFloor, "max": int >= min }` — the shared range shape used by both
     * `motion_duration_ms` and each of `motion_speed_profile`'s three waypoint-speed ranges. */
    private static function isValidIntRange(mixed $range, int $minFloor = 1): bool
    {
        return is_array($range)
            && isset($range['min'], $range['max'])
            && is_int($range['min']) && is_int($range['max'])
            && $range['min'] >= $minFloor && $range['max'] >= $range['min'];
    }

    /** CR-TEST-24: reject-and-reroll cap — never loop forever if a misconfigured (but individually
     * valid-per-field) profile makes the 1-5s window unreachable; this many attempts is generous
     * for any sane description and fails loudly (INTERNAL_ERROR) rather than hanging a request. */
    private const MAX_SPEED_PROFILE_REROLLS = 100;

    private const MIN_MOTION_DURATION_MS = 1000;
    private const MAX_MOTION_DURATION_MS = 5000;

    /** CR-TEST-24: per-circle radius varies ±20% of CR-TEST-23's baseline circle_radius_px. */
    private const CIRCLE_RADIUS_VARIANCE_PCT = 20;

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

        $hasMotionDuration = array_key_exists('motion_duration_ms', $description);
        $hasSpeedProfile = array_key_exists('motion_speed_profile', $description);
        $hasRadius = array_key_exists('circle_radius_px', $description);
        $perCircleRadius = $hasRadius && $hasSpeedProfile; // CR-TEST-24 only: per-circle, per-trial.
        $baseRadius = $hasRadius ? (int) $description['circle_radius_px'] : null;

        $total = $trialCount + $bufferTrials;
        if ($total > self::MAX_TRIAL_GENERATION_ITERATIONS) {
            // CR-TEST-27 defense-in-depth: validateDescription() above already rejects an
            // oversized trial_count at import time, but this loop must never trust that as its
            // only line of defense — any future bypass (or another code path reaching compile()
            // with an unvalidated description) must still fail safely with a caught ApiException/
            // JSON-enveloped 500, never an uncaught PHP Fatal leaking a raw stack trace and
            // internal file path to the HTTP response.
            throw new ApiException(ErrorCode::INTERNAL_ERROR, 500, ['reason' => 'TRIAL_COUNT_ITERATION_CEILING_EXCEEDED']);
        }
        $trials = [];
        for ($i = 0; $i < $total; $i++) {
            $trial = ['index' => $i, 'delay_ms' => Rand::intBetween($min, $max)];

            if ($hasMotionDuration) {
                // CR-TEST-23: single resolved duration per trial, same pattern as delay_ms.
                $trial['motion_duration_ms'] = Rand::intBetween(
                    (int) $description['motion_duration_ms']['min'],
                    (int) $description['motion_duration_ms']['max'],
                );
            } elseif ($hasSpeedProfile) {
                // CR-TEST-24: resolve waypoint speeds, verify total duration lands in [1000,5000]ms
                // — reject-and-reroll rather than ever shipping an out-of-bound schedule.
                $trial['motion_speed_profile'] = self::resolveSpeedProfile($description['motion_speed_profile']);
            }

            if ($perCircleRadius) {
                // CR-TEST-24: each circle independently resolved within +-20% of the baseline —
                // genuinely per-circle, per-trial, not just trial-to-trial.
                $trial['circle_radius_px'] = [
                    'a' => self::resolveVariedRadius($baseRadius),
                    'b' => self::resolveVariedRadius($baseRadius),
                ];
            }

            $trials[] = $trial;
        }

        $schedule = [
            'trial_count' => $trialCount,
            'buffer_trials' => $bufferTrials,
            'response_channels' => array_values($description['response_channels']),
            'timeout_ms' => $description['timeout_ms'] ?? null,
            'trials' => $trials,
        ];

        if (array_key_exists('allow_early_response', $description)) {
            $schedule['allow_early_response'] = (bool) $description['allow_early_response'];
        }
        // circle_radius_px stays schedule-level (constant) for CR-TEST-23's simple variant, where
        // it never varies per-trial/per-circle; CR-TEST-24 instead carries it per-trial/per-circle
        // above (trials[].circle_radius_px), so it's deliberately omitted here in that case.
        if ($hasRadius && !$perCircleRadius) {
            $schedule['circle_radius_px'] = $baseRadius;
        }

        return $schedule;
    }

    /**
     * Resolves one trial's three waypoint speeds from the description's ranges, verifies the
     * implied total motion duration (travel_distance_px covered at the average of a linear
     * start->mid->end speed ramp) lands in [1000,5000]ms, and re-rolls if not — never returns an
     * out-of-bound resolved profile.
     * @param array<string,mixed> $profile
     * @return array<string,mixed> { start_speed_px_per_s, mid_speed_px_per_s, end_speed_px_per_s, duration_ms }
     */
    private static function resolveSpeedProfile(array $profile): array
    {
        $distance = (int) $profile['travel_distance_px'];

        for ($attempt = 0; $attempt < self::MAX_SPEED_PROFILE_REROLLS; $attempt++) {
            $start = Rand::intBetween((int) $profile['start_speed_px_per_s']['min'], (int) $profile['start_speed_px_per_s']['max']);
            $mid = Rand::intBetween((int) $profile['mid_speed_px_per_s']['min'], (int) $profile['mid_speed_px_per_s']['max']);
            $end = Rand::intBetween((int) $profile['end_speed_px_per_s']['min'], (int) $profile['end_speed_px_per_s']['max']);

            // Two-leg piecewise-linear ramp (start->mid over the first half of the distance,
            // mid->end over the second half): each leg's time = leg_distance / average_speed.
            // average_speed of a linear ramp between two speeds is their arithmetic mean.
            $legDistance = $distance / 2;
            $firstLegAvgSpeed = ($start + $mid) / 2;
            $secondLegAvgSpeed = ($mid + $end) / 2;
            if ($firstLegAvgSpeed <= 0 || $secondLegAvgSpeed <= 0) {
                continue; // shouldn't happen given validateDescription's min >= 1, but never divide by <=0.
            }
            $durationMs = (int) round((($legDistance / $firstLegAvgSpeed) + ($legDistance / $secondLegAvgSpeed)) * 1000);

            if ($durationMs >= self::MIN_MOTION_DURATION_MS && $durationMs <= self::MAX_MOTION_DURATION_MS) {
                return [
                    'start_speed_px_per_s' => $start,
                    'mid_speed_px_per_s' => $mid,
                    'end_speed_px_per_s' => $end,
                    'duration_ms' => $durationMs,
                ];
            }
        }

        // Every attempt landed outside [1000,5000]ms — the description's configured ranges can't
        // produce a valid trial at all (a real misconfiguration, not bad luck). Never ship an
        // out-of-bound schedule; fail loudly instead so the admin import gets caught, not a silent
        // bad trial reaching a real user.
        throw new ApiException(ErrorCode::INTERNAL_ERROR, 500, ['reason' => 'SPEED_PROFILE_UNRESOLVABLE']);
    }

    private static function resolveVariedRadius(int $baseRadius): int
    {
        $varianceRange = (int) round($baseRadius * self::CIRCLE_RADIUS_VARIANCE_PCT / 100);
        if ($varianceRange < 1) {
            return $baseRadius;
        }
        return $baseRadius + Rand::intBetween(-$varianceRange, $varianceRange);
    }
}
