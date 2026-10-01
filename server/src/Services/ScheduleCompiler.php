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
 * Sprint 14 (K3/K5/K7/K8) new test families — four new test types that use a different client-
 * side interaction model where the client computes per-trial metrics (reaction time, movement
 * time, error, timing error, etc.) and submits them directly. These descriptions do NOT carry
 * inter_stimulus_delay_ms or response_channels (the client manages its own timing), so they
 * cannot share the classic compiled-trials-array approach. Detection is by the presence of their
 * own discriminating description fields:
 *   - random-target-pointing  → presence of "target_diameter_px"
 *   - choice-reaction-geometry → presence of "shapes"
 *   - peripheral-reaction      → presence of "positions"
 *   - temporal-prediction      → presence of "circle_speed_px_per_ms"
 * Their compiled schedule carries schedule_family = "custom-kpi", trial_count, and the validated
 * description fields verbatim (no per-trial resolution is needed — no random delays to draw).
 * RunService and ResultSummaryService branch on schedule_family to apply appropriate validation
 * and aggregation for these types.
 *
 * CR-TEST-23 (Sprint 11, Circle Collision Simple) adds three optional fields, all resolved into
 * the compiled schedule exactly like inter_stimulus_delay_ms already was — concrete per-trial
 * numbers only, never the raw min/max range itself (D11):
 * {
 *   "motion_duration_ms": {"min": int > 0, "max": int >= min}, // resolved per-trial -> duration_ms
 *   "circle_radius_px": int > 0,       // constant for this simple variant; resolved schedule-level
 *   "allow_early_response": bool,      // true = skip REACTION_BEFORE_STIMULUS for every trial here
 *   "travel_distance_px": int > 0      // CR-TEST-28, optional; defaults to DEFAULT_TRAVEL_DISTANCE_PX
 * }
 * A coincidence-anticipation test (no discrete stimulus onset — the user watches continuous motion
 * and clicks when they judge it reaches some point) sets allow_early_response: true so a response
 * any time after motion starts, including well before the visual "collision," is valid data, not a
 * rejected false start (RunService::validateTrialLog). motion_duration_ms/circle_radius_px are
 * omitted entirely for a classic discrete-stimulus test (simple-reaction, two-hand-reaction) — the
 * compiled schedule simply doesn't carry those keys when absent from the description.
 *
 * CR-TEST-28 (Sprint 13): both Circle Collision variants' compiled trials[] gain a resolved
 * `closing_speed_px_per_ms` (float > 0) — the constant-for-Simple / average-effective-for-Complex
 * closing rate, i.e. `travel_distance_px / (motion_duration_ms | motion_speed_profile.duration_ms)`
 * — so the client (or the server itself) can derive a resolved, server-computed
 * distance-at-click-in-px from a trial's `stimulus_at`/response timestamps:
 * `distance_px = closing_speed_px_per_ms * (response_at - stimulus_at)`, signed the same way the
 * existing ms delta already is (D11: never a value the client anticipates independently of what
 * the compiled schedule provides). See `_API_CONTRACT/CONTRACT.md` v1.8.
 *
 * Compiled schedule shape (sent to client + stored server-side for submission validation):
 * {
 *   "trial_count": int,               // the number of VALID trials the client must submit
 *   "response_channels": [...],
 *   "timeout_ms": int|null,
 *   "allow_early_response": bool,     // CR-TEST-23, only present when the description set it
 *   "circle_radius_px": int,          // CR-TEST-23, only present when the description set it
 *   "trials": [ { "index": int, "delay_ms": int, "motion_duration_ms"?: int, "closing_speed_px_per_ms"?: float }, ... ]
 *     // trial_count + buffer_trials entries; motion_duration_ms per-trial when CR-TEST-23 fields
 *     // are present (same per-trial-resolved pattern as delay_ms — a fresh random draw per trial,
 *     // not one fixed value reused for the whole schedule, so a client can't learn the value from
 *     // trial 1 and anticipate the rest). closing_speed_px_per_ms (CR-TEST-28) is present per-trial
 *     // whenever either Circle Collision variant's motion fields are present.
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

    /** CR-TEST-28: default logical full-stage travel distance (px) for Circle Collision Simple
     * when the description omits `travel_distance_px` — matches Complex's existing seed convention
     * (`circle-collision-complex.v1.json`'s `travel_distance_px: 800`) so both variants resolve a
     * comparable closing speed out of the box. A logical distance, not a literal on-screen pixel
     * count (the client's actual rendered stage width varies per viewport, per
     * `circle-collision-simple.js`'s `stage.offsetWidth` — see closing_speed_px_per_ms doc below);
     * used only as the resolved basis for the closing-speed figure. */
    public const DEFAULT_TRAVEL_DISTANCE_PX = 800;

    /** CR-TEST-27: sane upper bound on an imported description's trial_count — comfortably above
     * any real r-test's actual value (the largest seeded test uses 10) while ruling out a
     * pathological import (e.g. trial_count: 1000000) that would otherwise reach the
     * trial-generation loop in compile() and exhaust memory/time. Enforced at import-validation
     * time here; MAX_TRIAL_GENERATION_ITERATIONS below is the defense-in-depth backstop inside the
     * loop itself, in case this check is ever bypassed by a future code path. */
    public const MAX_TRIAL_COUNT = 500;

    /** CR-TEST-29: sane upper bound on an imported description's false_start_buffer — comfortably
     * above any real r-test's actual value (no seed today sets this explicitly; all real r-tests
     * rely on DEFAULT_FALSE_START_BUFFER = 6) while ruling out a pathological import (e.g.
     * false_start_buffer: 1000000) that would otherwise reach the trial-generation loop in
     * compile() and exhaust memory/time, same reasoning as MAX_TRIAL_COUNT above. Kept comfortably
     * below MAX_TRIAL_GENERATION_ITERATIONS - MAX_TRIAL_COUNT (currently 1500) so this import-time
     * check always fires before the defense-in-depth ceiling would. */
    public const MAX_FALSE_START_BUFFER = 100;

    /** CR-TEST-27: hard ceiling on trial-generation loop iterations (trial_count + buffer_trials),
     * independent of MAX_TRIAL_COUNT's import-time check — never let an unvalidated/bypassed
     * trial_count reach an uncaught PHP Fatal (e.g. exhausting memory) here. Comfortably above
     * MAX_TRIAL_COUNT + any sane false_start_buffer. */
    private const MAX_TRIAL_GENERATION_ITERATIONS = 2000;

    /** @param array<string,mixed> $description @throws ApiException */
    public static function validateDescription(array $description): void
    {
        // Sprint 14 (K3/K5/K7/K8): detect new custom-KPI test families by their discriminating
        // fields and route to their own validators before touching classic required fields.
        if (array_key_exists('target_diameter_px', $description)) {
            self::validateRandomTargetPointing($description);
            return;
        }
        if (array_key_exists('shapes', $description)) {
            self::validateChoiceReactionGeometry($description);
            return;
        }
        if (array_key_exists('positions', $description)) {
            self::validatePeripheralReaction($description);
            return;
        }
        if (array_key_exists('circle_speed_px_per_ms', $description)) {
            self::validateTemporalPrediction($description);
            return;
        }
        if (array_key_exists('condition_ratio', $description)) {
            self::validateVisualConflict($description);
            return;
        }
        // NOTE: isCustomKpiDescription() below must stay in sync with this detection order.

        // --- Classic stimulus/response family ---
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
            if (!is_int($buffer) || $buffer < 0 || $buffer > self::MAX_FALSE_START_BUFFER) {
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

        // CR-TEST-28 (Circle Collision Simple only): optional resolved basis for
        // closing_speed_px_per_ms — see DEFAULT_TRAVEL_DISTANCE_PX doc. Complex already carries its
        // own travel_distance_px inside motion_speed_profile (CR-TEST-24), so this top-level key is
        // only meaningful (and only validated) alongside motion_duration_ms.
        if (array_key_exists('travel_distance_px', $description) && array_key_exists('motion_duration_ms', $description)) {
            $distance = $description['travel_distance_px'];
            if (!is_int($distance) || $distance < 1) {
                $errors[] = 'travel_distance_px';
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

    // -------------------------------------------------------------------------
    // Sprint 14 (K3/K5/K7/K8) — custom-KPI test family validators
    // -------------------------------------------------------------------------

    /** Returns true when $description belongs to the Sprint 14/15 custom-KPI family. */
    private static function isCustomKpiDescription(array $description): bool
    {
        return array_key_exists('target_diameter_px', $description)
            || array_key_exists('shapes', $description)
            || array_key_exists('positions', $description)
            || array_key_exists('circle_speed_px_per_ms', $description)
            || array_key_exists('condition_ratio', $description);
    }

    /** Shared: validates trial_count (required, 1..MAX_TRIAL_COUNT) and appends to $errors. */
    private static function validateTrialCount(array $description, array &$errors): void
    {
        $trialCount = $description['trial_count'] ?? null;
        if (!is_int($trialCount) || $trialCount < 1 || $trialCount > self::MAX_TRIAL_COUNT) {
            $errors[] = 'trial_count';
        }
    }

    /** Shared: validates optional inter_trial_interval_ms (int >= 0) and appends to $errors. */
    private static function validateOptionalIti(array $description, array &$errors): void
    {
        if (array_key_exists('inter_trial_interval_ms', $description)) {
            $iti = $description['inter_trial_interval_ms'];
            if (!is_int($iti) || $iti < 0) {
                $errors[] = 'inter_trial_interval_ms';
            }
        }
    }

    /** Shared: validates optional randomize_delay_range_ms ({min,max}, both >= 0) and appends to $errors. */
    private static function validateOptionalDelayRange(array $description, array &$errors): void
    {
        if (array_key_exists('randomize_delay_range_ms', $description)) {
            if (!self::isValidIntRange($description['randomize_delay_range_ms'], 0)) {
                $errors[] = 'randomize_delay_range_ms';
            }
        }
    }

    /** Shared: throws VALIDATION_ERROR if $errors is non-empty. */
    private static function throwIfErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => array_values(array_unique($errors))]);
        }
    }

    /**
     * CR-TEST-30 — `random-target-pointing` description validation.
     * Fields: target_diameter_px (> 0), min_distance_from_prev_px (>= 0), trial_count (<=
     * MAX_TRIAL_COUNT), response_timeout_ms (> 0), inter_trial_interval_ms (>= 0),
     * randomize_delay_range_ms.min/.max (min <= max, both >= 0), record_trajectory (bool).
     * @param array<string,mixed> $description @throws ApiException
     */
    private static function validateRandomTargetPointing(array $description): void
    {
        $errors = [];

        self::validateTrialCount($description, $errors);

        $diameter = $description['target_diameter_px'] ?? null;
        if (!is_int($diameter) || $diameter < 1) {
            $errors[] = 'target_diameter_px';
        }

        if (array_key_exists('min_distance_from_prev_px', $description)) {
            $minDist = $description['min_distance_from_prev_px'];
            if (!is_int($minDist) || $minDist < 0) {
                $errors[] = 'min_distance_from_prev_px';
            }
        }

        $timeout = $description['response_timeout_ms'] ?? null;
        if (!is_int($timeout) || $timeout < 1) {
            $errors[] = 'response_timeout_ms';
        }

        self::validateOptionalIti($description, $errors);
        self::validateOptionalDelayRange($description, $errors);

        if (array_key_exists('record_trajectory', $description)) {
            if (!is_bool($description['record_trajectory'])) {
                $errors[] = 'record_trajectory';
            }
        }

        self::throwIfErrors($errors);
    }

    /**
     * CR-TEST-32 — `choice-reaction-geometry` description validation.
     * Fields: shapes (non-empty array of known values), key_mapping (object with entry for every
     * shape), trial_count (<= MAX_TRIAL_COUNT), response_window_ms (> 0), inter_trial_interval_ms
     * (>= 0), randomize_delay_range_ms, shape_size_px (> 0), show_mapping_during_measurement (bool).
     * @param array<string,mixed> $description @throws ApiException
     */
    private static function validateChoiceReactionGeometry(array $description): void
    {
        $errors = [];

        self::validateTrialCount($description, $errors);

        $shapes = $description['shapes'] ?? null;
        $validShapes = ['triangle', 'circle', 'square', 'diamond', 'star'];
        if (
            !is_array($shapes)
            || count($shapes) < 1
            || array_filter($shapes, static fn ($s) => !is_string($s) || !in_array($s, $validShapes, true)) !== []
        ) {
            $errors[] = 'shapes';
            // If shapes is invalid we can't validate key_mapping against it, so mark both and bail.
            $errors[] = 'key_mapping';
        } else {
            $keyMapping = $description['key_mapping'] ?? null;
            if (!is_array($keyMapping)) {
                $errors[] = 'key_mapping';
            } else {
                // Every shape in $shapes must have a non-empty string key in key_mapping.
                foreach ($shapes as $shape) {
                    if (!array_key_exists($shape, $keyMapping) || !is_string($keyMapping[$shape]) || $keyMapping[$shape] === '') {
                        $errors[] = 'key_mapping';
                        break;
                    }
                }
            }
        }

        $responseWindow = $description['response_window_ms'] ?? null;
        if (!is_int($responseWindow) || $responseWindow < 1) {
            $errors[] = 'response_window_ms';
        }

        self::validateOptionalIti($description, $errors);
        self::validateOptionalDelayRange($description, $errors);

        if (array_key_exists('shape_size_px', $description)) {
            $size = $description['shape_size_px'];
            if (!is_int($size) || $size < 1) {
                $errors[] = 'shape_size_px';
            }
        }

        if (array_key_exists('show_mapping_during_measurement', $description)) {
            if (!is_bool($description['show_mapping_during_measurement'])) {
                $errors[] = 'show_mapping_during_measurement';
            }
        }

        self::throwIfErrors($errors);
    }

    /**
     * CR-TEST-34 — `peripheral-reaction` description validation.
     * Fields: positions (non-empty array, each with angle_deg in [0,360] and eccentricity_px > 0,
     * and an optional label string), stimulus_diameter_px (> 0), trial_count (<= MAX_TRIAL_COUNT),
     * response_window_ms (> 0), inter_trial_interval_ms (>= 0), randomize_delay_range_ms,
     * response_type ("key" | "click").
     * @param array<string,mixed> $description @throws ApiException
     */
    private static function validatePeripheralReaction(array $description): void
    {
        $errors = [];

        self::validateTrialCount($description, $errors);

        $positions = $description['positions'] ?? null;
        if (!is_array($positions) || count($positions) < 1) {
            $errors[] = 'positions';
        } else {
            foreach ($positions as $pos) {
                if (!is_array($pos)) {
                    $errors[] = 'positions';
                    break;
                }
                $angle = $pos['angle_deg'] ?? null;
                $ecc   = $pos['eccentricity_px'] ?? null;
                if (
                    (!is_int($angle) && !is_float($angle))
                    || $angle < 0 || $angle > 360
                    || (!is_int($ecc) && !is_float($ecc))
                    || $ecc <= 0
                ) {
                    $errors[] = 'positions';
                    break;
                }
                // label is optional; if present must be a non-empty string
                if (array_key_exists('label', $pos) && (!is_string($pos['label']) || $pos['label'] === '')) {
                    $errors[] = 'positions';
                    break;
                }
            }
        }

        if (array_key_exists('stimulus_diameter_px', $description)) {
            $diam = $description['stimulus_diameter_px'];
            if (!is_int($diam) || $diam < 1) {
                $errors[] = 'stimulus_diameter_px';
            }
        }

        $responseWindow = $description['response_window_ms'] ?? null;
        if (!is_int($responseWindow) || $responseWindow < 1) {
            $errors[] = 'response_window_ms';
        }

        self::validateOptionalIti($description, $errors);
        self::validateOptionalDelayRange($description, $errors);

        if (array_key_exists('response_type', $description)) {
            $rt = $description['response_type'];
            if (!in_array($rt, ['key', 'click'], true)) {
                $errors[] = 'response_type';
            }
        }

        self::throwIfErrors($errors);
    }

    /**
     * CR-TEST-35 — `temporal-prediction` description validation.
     * Fields: circle_speed_px_per_ms (> 0), target_line_x_ratio in (0,1),
     * start_x_ratio in (0,1) and < target_line_x_ratio, prediction_window_ms (> 0),
     * miss_tolerance_px (> 0), disappear_before_target_px (>= 0),
     * trial_count (<= MAX_TRIAL_COUNT), inter_trial_interval_ms (>= 0), circle_diameter_px (> 0).
     * @param array<string,mixed> $description @throws ApiException
     */
    private static function validateTemporalPrediction(array $description): void
    {
        $errors = [];

        self::validateTrialCount($description, $errors);

        $speed = $description['circle_speed_px_per_ms'] ?? null;
        if (!is_float($speed) && !is_int($speed)) {
            $errors[] = 'circle_speed_px_per_ms';
        } elseif ((float) $speed <= 0) {
            $errors[] = 'circle_speed_px_per_ms';
        }

        $targetRatio = $description['target_line_x_ratio'] ?? null;
        if ((!is_float($targetRatio) && !is_int($targetRatio)) || (float) $targetRatio <= 0 || (float) $targetRatio >= 1) {
            $errors[] = 'target_line_x_ratio';
        }

        $startRatio = $description['start_x_ratio'] ?? null;
        if ((!is_float($startRatio) && !is_int($startRatio)) || (float) $startRatio <= 0 || (float) $startRatio >= 1) {
            $errors[] = 'start_x_ratio';
        }

        // start_x_ratio must be strictly less than target_line_x_ratio.
        if (!in_array('start_x_ratio', $errors, true) && !in_array('target_line_x_ratio', $errors, true)) {
            if ((float) $startRatio >= (float) $targetRatio) {
                $errors[] = 'start_x_ratio';
            }
        }

        $predWindow = $description['prediction_window_ms'] ?? null;
        if (!is_int($predWindow) || $predWindow < 1) {
            $errors[] = 'prediction_window_ms';
        }

        if (array_key_exists('miss_tolerance_px', $description)) {
            $missTol = $description['miss_tolerance_px'];
            if (!is_int($missTol) || $missTol < 1) {
                $errors[] = 'miss_tolerance_px';
            }
        }

        if (array_key_exists('disappear_before_target_px', $description)) {
            $disappear = $description['disappear_before_target_px'];
            if (!is_int($disappear) || $disappear < 0) {
                $errors[] = 'disappear_before_target_px';
            }
        }

        self::validateOptionalIti($description, $errors);

        if (array_key_exists('circle_diameter_px', $description)) {
            $diam = $description['circle_diameter_px'];
            if (!is_int($diam) || $diam < 1) {
                $errors[] = 'circle_diameter_px';
            }
        }

        self::throwIfErrors($errors);
    }

    /**
     * CR-TEST-33 (Sprint 15) — `visual-conflict` description validation.
     * Fields: condition_ratio (object with neutral/congruent/conflict keys summing to 1.0 ±0.01),
     * pretrain_trial_count (>= 1), trial_count (<= MAX_TRIAL_COUNT), response_window_ms (> 0),
     * inter_trial_interval_ms (>= 0), randomize_delay_range_ms, shape_size_px (> 0),
     * phase_transition_display_ms (>= 0).
     * @param array<string,mixed> $description @throws ApiException
     */
    private static function validateVisualConflict(array $description): void
    {
        $errors = [];

        self::validateTrialCount($description, $errors);

        // condition_ratio: must have neutral/congruent/conflict keys, all numeric,
        // and their sum must equal 1.0 within ±0.01 float tolerance.
        $ratio = $description['condition_ratio'] ?? null;
        if (!is_array($ratio)) {
            $errors[] = 'condition_ratio';
        } else {
            $neutral   = $ratio['neutral']   ?? null;
            $congruent = $ratio['congruent'] ?? null;
            $conflict  = $ratio['conflict']  ?? null;
            if (
                (!is_int($neutral)   && !is_float($neutral))
                || (!is_int($congruent) && !is_float($congruent))
                || (!is_int($conflict)  && !is_float($conflict))
                || (float) $neutral   < 0
                || (float) $congruent < 0
                || (float) $conflict  < 0
            ) {
                $errors[] = 'condition_ratio';
            } else {
                $sum = (float) $neutral + (float) $congruent + (float) $conflict;
                if (abs($sum - 1.0) > 0.01) {
                    $errors[] = 'condition_ratio';
                }
            }
        }

        // pretrain_trial_count: required, >= 1
        $pretrainCount = $description['pretrain_trial_count'] ?? null;
        if (!is_int($pretrainCount) || $pretrainCount < 1) {
            $errors[] = 'pretrain_trial_count';
        }

        // response_window_ms: required, > 0
        $responseWindow = $description['response_window_ms'] ?? null;
        if (!is_int($responseWindow) || $responseWindow < 1) {
            $errors[] = 'response_window_ms';
        }

        // phase_transition_display_ms: optional, >= 0
        if (array_key_exists('phase_transition_display_ms', $description)) {
            $ptd = $description['phase_transition_display_ms'];
            if (!is_int($ptd) || $ptd < 0) {
                $errors[] = 'phase_transition_display_ms';
            }
        }

        self::validateOptionalIti($description, $errors);
        self::validateOptionalDelayRange($description, $errors);

        // shape_size_px: optional, > 0
        if (array_key_exists('shape_size_px', $description)) {
            $size = $description['shape_size_px'];
            if (!is_int($size) || $size < 1) {
                $errors[] = 'shape_size_px';
            }
        }

        self::throwIfErrors($errors);
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

        // Sprint 14 (K3/K5/K7/K8): custom-KPI test families — no per-trial delay resolution
        // needed; the description IS the schedule (the client handles its own timing).
        // validateDescription() already ran the discriminating-field check above; here we check the
        // same predicate rather than duplicating the field names.
        if (self::isCustomKpiDescription($description)) {
            return array_merge($description, ['schedule_family' => 'custom-kpi']);
        }

        $trialCount = (int) $description['trial_count'];
        $bufferTrials = (int) ($description['false_start_buffer'] ?? self::DEFAULT_FALSE_START_BUFFER);
        $min = (int) $description['inter_stimulus_delay_ms']['min'];
        $max = (int) $description['inter_stimulus_delay_ms']['max'];

        $hasMotionDuration = array_key_exists('motion_duration_ms', $description);
        $hasSpeedProfile = array_key_exists('motion_speed_profile', $description);
        $hasRadius = array_key_exists('circle_radius_px', $description);
        $perCircleRadius = $hasRadius && $hasSpeedProfile; // CR-TEST-24 only: per-circle, per-trial.
        $baseRadius = $hasRadius ? (int) $description['circle_radius_px'] : null;
        // CR-TEST-28: Simple's resolved travel_distance_px basis for closing_speed_px_per_ms (see
        // that field's doc below) — description-supplied or DEFAULT_TRAVEL_DISTANCE_PX. Complex
        // carries its own travel_distance_px inside motion_speed_profile already (CR-TEST-24), read
        // directly from the resolved profile per-trial below instead.
        $simpleTravelDistancePx = $hasMotionDuration
            ? (int) ($description['travel_distance_px'] ?? self::DEFAULT_TRAVEL_DISTANCE_PX)
            : null;

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
                $motionDurationMs = Rand::intBetween(
                    (int) $description['motion_duration_ms']['min'],
                    (int) $description['motion_duration_ms']['max'],
                );
                $trial['motion_duration_ms'] = $motionDurationMs;
                // CR-TEST-28: resolved closing speed for this trial — constant for the whole trial
                // (Simple has no mid-trial speed change), server-resolved per D11 from the two
                // values just resolved above, never something the client is trusted to anticipate.
                $trial['closing_speed_px_per_ms'] = $simpleTravelDistancePx / $motionDurationMs;
            } elseif ($hasSpeedProfile) {
                // CR-TEST-24: resolve waypoint speeds, verify total duration lands in [1000,5000]ms
                // — reject-and-reroll rather than ever shipping an out-of-bound schedule.
                $resolvedProfile = self::resolveSpeedProfile($description['motion_speed_profile']);
                $trial['motion_speed_profile'] = $resolvedProfile;
                // CR-TEST-28: Complex's closing speed varies within a trial (three waypoints), so
                // this is the trial's *average effective* rate (travel_distance_px / total resolved
                // duration) — sufficient for the client to compute distance-at-click without
                // re-deriving the full piecewise ramp math server-side; "your call on how to express
                // an effective closing rate" per the CR. Server-resolved from values already
                // resolved above (travel_distance_px is part of the description's speed-profile
                // range input, duration_ms was just verified by resolveSpeedProfile) — never
                // client-anticipated.
                $trial['closing_speed_px_per_ms'] = ((int) $description['motion_speed_profile']['travel_distance_px'])
                    / $resolvedProfile['duration_ms'];
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
        // CR-TEST-28 (SI-13.4): generic schedule-level flag — same pattern as allow_early_response
        // just above (not a special case keyed to this test's slug, per the established convention
        // at RunService::validateTrialLog's allow_early_response doc) — tells ResultSummaryService
        // to aggregate this schedule's mean/sd using each trial's absolute value rather than the
        // signed value every other test type uses. True exactly when either Circle Collision
        // variant's motion fields are present (i.e. whenever closing_speed_px_per_ms is resolved
        // per-trial above), so any future coincidence-distance r-test type can opt in the same way.
        if ($hasMotionDuration || $hasSpeedProfile) {
            $schedule['abs_value_aggregation'] = true;
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
