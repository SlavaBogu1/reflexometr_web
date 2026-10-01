<?php

declare(strict_types=1);

namespace Reflexometr\Services;

/**
 * Computes a stored summary + a single generic "primary metric" (mean reaction time in ms,
 * lower = faster) from a validated trial log. The primary metric is what StatsService ranks
 * percentiles on — kept generic (not per-r-test-type bespoke code) so a newly imported r-test
 * needs no new server code to participate in stats (in the spirit of D11: this project only
 * provides a generic runtime).
 *
 * CR-STATS-08: also computes sd_ms (sample standard deviation) and cv (coefficient of variation,
 * sd_ms / mean_ms) — overall, and per-channel for multi-channel (two-hand) tests, matching the
 * existing summary.channels breakdown.
 *
 * CR-TEST-28 (SI-13.4): when the compiled schedule sets `abs_value_aggregation` (Circle Collision
 * only — see ScheduleCompiler::compile()'s doc on that flag), mean/median/sd/cv/primary metric
 * aggregate the **absolute value** of each trial's signed value instead of the signed value
 * itself — a mixed set of early (negative) and late (positive) trials reflects accuracy
 * *magnitude*, not a net-cancelling signed average. `min`/`max` (unsuffixed — "ms" would be
 * misleading for what's actually a px distance for this family, per the CR's naming note) are
 * the one exception: they always reflect the genuinely signed extremes (earliest/latest
 * anticipation) regardless of this flag, so a user can still see their early/late split. Every
 * other test type's signed-average convention (CR-TEST-23/24's v1.6 contract note) is completely
 * unchanged — this flag defaults to false/absent and only Circle Collision's compiled schedule
 * ever sets it (test-type-aware via the schedule, not a global behavior change).
 */
final class ResultSummaryService
{
    /**
     * @param array<string,mixed> $schedule Compiled schedule (has response_channels).
     * @param array<int,array<string,mixed>> $trials Validated submitted trial log.
     * @return array{summary: array<string,mixed>, primaryMetricMs: float, sdMs: ?float, cv: ?float}
     */
    public static function compute(array $schedule, array $trials, ?string $dominantHand): array
    {
        // Sprint 14 (K3/K5/K7/K8): dispatch to custom-KPI aggregators.
        if (($schedule['schedule_family'] ?? '') === 'custom-kpi') {
            if (array_key_exists('target_diameter_px', $schedule)) {
                return self::computeRandomTargetPointing($trials);
            }
            if (array_key_exists('shapes', $schedule)) {
                return self::computeChoiceReactionGeometry($trials);
            }
            if (array_key_exists('positions', $schedule)) {
                return self::computePeripheralReaction($trials);
            }
            if (array_key_exists('circle_speed_px_per_ms', $schedule)) {
                return self::computeTemporalPrediction($trials);
            }
        }

        $channels = $schedule['response_channels'];
        $absValueAggregation = (bool) ($schedule['abs_value_aggregation'] ?? false);
        /** @var array<string,array<int,float>> $perChannelSignedTimes */
        $perChannelSignedTimes = array_fill_keys($channels, []);
        $allSignedTimes = [];
        $timeoutCounts = array_fill_keys($channels, 0);

        foreach ($trials as $trial) {
            $stimulusAt = (float) $trial['stimulus_at'];
            $responses = $trial['responses'];
            foreach ($channels as $channel) {
                $value = $responses[$channel] ?? null;
                if ($value === null) {
                    $timeoutCounts[$channel]++;
                    continue;
                }
                $rt = (float) $value - $stimulusAt;
                $perChannelSignedTimes[$channel][] = $rt;
                $allSignedTimes[] = $rt;
            }
        }

        $channelStats = [];
        foreach ($channels as $channel) {
            $signedTimes = $perChannelSignedTimes[$channel];
            $aggregationTimes = $absValueAggregation ? array_map('abs', $signedTimes) : $signedTimes;
            $channelMeanMs = self::mean($aggregationTimes);
            $channelSdMs = self::stddev($aggregationTimes);
            $channelStats[$channel] = [
                'mean_ms' => $channelMeanMs,
                'median_ms' => self::median($aggregationTimes),
                'sd_ms' => $channelSdMs,
                'cv' => self::coefficientOfVariation($channelSdMs, $channelMeanMs),
                'timeouts' => $timeoutCounts[$channel],
                'valid_count' => count($signedTimes),
            ] + self::signedExtremes($signedTimes, $absValueAggregation);
        }

        $allAggregationTimes = $absValueAggregation ? array_map('abs', $allSignedTimes) : $allSignedTimes;
        $overallMeanMs = self::mean($allAggregationTimes);
        $overallSdMs = self::stddev($allAggregationTimes);
        $summary = [
            'overall' => [
                'mean_ms' => $overallMeanMs,
                'median_ms' => self::median($allAggregationTimes),
                'sd_ms' => $overallSdMs,
                'cv' => self::coefficientOfVariation($overallSdMs, $overallMeanMs),
                'valid_count' => count($allAggregationTimes),
            ] + self::signedExtremes($allSignedTimes, $absValueAggregation),
            'channels' => $channelStats,
        ];

        if (count($channels) === 2) {
            [$a, $b] = $channels;
            $meanA = $channelStats[$a]['mean_ms'];
            $meanB = $channelStats[$b]['mean_ms'];
            if ($meanA !== null && $meanB !== null && in_array($dominantHand, ['left', 'right'], true) && in_array('left', $channels, true) && in_array('right', $channels, true)) {
                $dominantMean = $dominantHand === 'left' ? $channelStats['left']['mean_ms'] : $channelStats['right']['mean_ms'];
                $nonDominantMean = $dominantHand === 'left' ? $channelStats['right']['mean_ms'] : $channelStats['left']['mean_ms'];
                // Negative means the dominant hand was faster (CR-TEST-04).
                $summary['dominant_minus_nondominant_ms'] = $dominantMean - $nonDominantMean;
            }
        }

        $primaryMetricMs = $overallMeanMs ?? 0.0;

        return [
            'summary' => $summary,
            'primaryMetricMs' => $primaryMetricMs,
            'sdMs' => $overallSdMs,
            'cv' => self::coefficientOfVariation($overallSdMs, $overallMeanMs),
        ];
    }

    // -------------------------------------------------------------------------
    // Sprint 14 (K3/K5/K7/K8) — custom-KPI aggregators
    // -------------------------------------------------------------------------

    /**
     * CR-TEST-30 — `random-target-pointing` KPIs.
     * Per-trial fields expected: reaction_time_ms (numeric|null), movement_time_ms (numeric|null),
     * click_error_px (numeric|null), result ("TRUE_RESPONSE"|"FALSE_RESPONSE"|"MISSED_STIMULUS").
     *
     * Aggregates reaction_time_ms/movement_time_ms/click_error_px for TRUE_RESPONSE trials only
     * (each with median, mean, SD, P10/25/50/75/90). Reports false_response_rate and
     * missed_stimulus_rate over all trials. Primary metric = median reaction_time_ms (TRUE only).
     * @param array<int,array<string,mixed>> $trials
     * @return array{summary: array<string,mixed>, primaryMetricMs: float, sdMs: ?float, cv: ?float}
     */
    private static function computeRandomTargetPointing(array $trials): array
    {
        $total = count($trials);
        $falseCount  = 0;
        $missedCount = 0;
        $rtTrue  = [];
        $mtTrue  = [];
        $errTrue = [];

        foreach ($trials as $trial) {
            $result = $trial['result'] ?? null;
            if ($result === 'FALSE_RESPONSE') {
                $falseCount++;
            } elseif ($result === 'MISSED_STIMULUS') {
                $missedCount++;
            }
            if ($result === 'TRUE_RESPONSE') {
                if (isset($trial['reaction_time_ms']) && is_numeric($trial['reaction_time_ms'])) {
                    $rtTrue[] = (float) $trial['reaction_time_ms'];
                }
                if (isset($trial['movement_time_ms']) && is_numeric($trial['movement_time_ms'])) {
                    $mtTrue[] = (float) $trial['movement_time_ms'];
                }
                if (isset($trial['click_error_px']) && is_numeric($trial['click_error_px'])) {
                    $errTrue[] = (float) $trial['click_error_px'];
                }
            }
        }

        $rtStats  = self::fullStats($rtTrue);
        $mtStats  = self::fullStats($mtTrue);
        $errStats = self::fullStats($errTrue);

        $primaryMetricMs = $rtStats['median'] ?? 0.0;
        $sdMs = $rtStats['sd'] ?? null;

        $summary = [
            'reaction_time_ms'    => $rtStats,
            'movement_time_ms'    => $mtStats,
            'click_error_px'      => $errStats,
            'false_response_rate' => $total > 0 ? $falseCount / $total : null,
            'missed_stimulus_rate' => $total > 0 ? $missedCount / $total : null,
            'true_response_count'  => count($rtTrue),
            'total_trials'         => $total,
        ];

        return [
            'summary' => $summary,
            'primaryMetricMs' => $primaryMetricMs,
            'sdMs' => $sdMs,
            'cv' => self::coefficientOfVariation($sdMs, $primaryMetricMs === 0.0 ? null : $primaryMetricMs),
        ];
    }

    /**
     * CR-TEST-32 — `choice-reaction-geometry` KPIs.
     * Per-trial fields expected: reaction_time_ms (numeric|null), result
     * ("TRUE_RESPONSE"|"FALSE_RESPONSE"|"MISSED_STIMULUS"|"INVALID_RESPONSE"), stimulus_shape,
     * response_key.
     *
     * Aggregates RT for TRUE_RESPONSE (correct) trials only. Reports correct_response_rate,
     * wrong_response_rate, missed_response_rate. Primary metric = mean correct-trial RT.
     * @param array<int,array<string,mixed>> $trials
     * @return array{summary: array<string,mixed>, primaryMetricMs: float, sdMs: ?float, cv: ?float}
     */
    private static function computeChoiceReactionGeometry(array $trials): array
    {
        $total        = count($trials);
        $correctCount = 0;
        $wrongCount   = 0;
        $missedCount  = 0;
        $correctRt    = [];

        foreach ($trials as $trial) {
            $result = $trial['result'] ?? null;
            if ($result === 'TRUE_RESPONSE') {
                $correctCount++;
                if (isset($trial['reaction_time_ms']) && is_numeric($trial['reaction_time_ms'])) {
                    $correctRt[] = (float) $trial['reaction_time_ms'];
                }
            } elseif ($result === 'FALSE_RESPONSE') {
                $wrongCount++;
            } elseif ($result === 'MISSED_STIMULUS') {
                $missedCount++;
            }
        }

        $rtStats = self::fullStats($correctRt);
        $meanRt  = $rtStats['mean'] ?? 0.0;
        $sdMs    = $rtStats['sd'] ?? null;

        $summary = [
            'reaction_time_ms'      => $rtStats,
            'correct_response_rate' => $total > 0 ? $correctCount / $total : null,
            'wrong_response_rate'   => $total > 0 ? $wrongCount / $total : null,
            'missed_response_rate'  => $total > 0 ? $missedCount / $total : null,
            'correct_count'         => $correctCount,
            'total_trials'          => $total,
        ];

        return [
            'summary' => $summary,
            'primaryMetricMs' => $meanRt,
            'sdMs' => $sdMs,
            'cv' => self::coefficientOfVariation($sdMs, $meanRt === 0.0 ? null : $meanRt),
        ];
    }

    /**
     * CR-TEST-34 — `peripheral-reaction` KPIs.
     * Per-trial fields expected: reaction_time_ms (numeric|null),
     * result ("TRUE_RESPONSE"|"FALSE_RESPONSE"|"MISSED_STIMULUS"),
     * stimulus_position_label (string), stimulus_angle_deg (numeric), stimulus_eccentricity_px (numeric).
     *
     * Overall RT stats for TRUE_RESPONSE trials. Per-position RT stats keyed by
     * stimulus_position_label when per-position count >= 3. Primary metric = mean RT.
     * @param array<int,array<string,mixed>> $trials
     * @return array{summary: array<string,mixed>, primaryMetricMs: float, sdMs: ?float, cv: ?float}
     */
    private static function computePeripheralReaction(array $trials): array
    {
        $total       = count($trials);
        $falseCount  = 0;
        $missedCount = 0;
        $overallRt   = [];
        /** @var array<string,array<int,float>> $byPosition */
        $byPosition  = [];

        foreach ($trials as $trial) {
            $result = $trial['result'] ?? null;
            if ($result === 'FALSE_RESPONSE') {
                $falseCount++;
            } elseif ($result === 'MISSED_STIMULUS') {
                $missedCount++;
            }
            if ($result === 'TRUE_RESPONSE' && isset($trial['reaction_time_ms']) && is_numeric($trial['reaction_time_ms'])) {
                $rt  = (float) $trial['reaction_time_ms'];
                $overallRt[] = $rt;
                $label = is_string($trial['stimulus_position_label'] ?? null) ? $trial['stimulus_position_label'] : null;
                if ($label !== null) {
                    $byPosition[$label][] = $rt;
                }
            }
        }

        $overallStats = self::fullStats($overallRt);
        $meanRt       = $overallStats['mean'] ?? 0.0;
        $sdMs         = $overallStats['sd'] ?? null;

        $perPosition = [];
        foreach ($byPosition as $label => $values) {
            if (count($values) >= 3) {
                $perPosition[$label] = self::fullStats($values);
            }
        }

        $summary = [
            'reaction_time_ms'     => $overallStats,
            'false_response_rate'  => $total > 0 ? $falseCount / $total : null,
            'missed_stimulus_rate' => $total > 0 ? $missedCount / $total : null,
            'true_response_count'  => count($overallRt),
            'total_trials'         => $total,
        ];

        if ($perPosition !== []) {
            $summary['per_position'] = $perPosition;
        }

        return [
            'summary' => $summary,
            'primaryMetricMs' => $meanRt,
            'sdMs' => $sdMs,
            'cv' => self::coefficientOfVariation($sdMs, $meanRt === 0.0 ? null : $meanRt),
        ];
    }

    /**
     * CR-TEST-35 — `temporal-prediction` KPIs.
     * Per-trial fields expected: timing_error_ms (numeric, signed), result
     * ("TRUE_RESPONSE"|"FALSE_RESPONSE"|"MISSED_STIMULUS"),
     * circle_visible_at_click (bool).
     *
     * For TRUE_RESPONSE trials: mean_signed_timing_error_ms, mean_abs_timing_error_ms,
     * median_abs_timing_error_ms, sd_timing_error_ms, early_pct, late_pct.
     * If both visible and hidden circle trials occur in the session, reports stats separately.
     * Primary metric = mean absolute timing error.
     * @param array<int,array<string,mixed>> $trials
     * @return array{summary: array<string,mixed>, primaryMetricMs: float, sdMs: ?float, cv: ?float}
     */
    private static function computeTemporalPrediction(array $trials): array
    {
        $total       = count($trials);
        $falseCount  = 0;
        $missedCount = 0;

        // Signed timing errors for TRUE_RESPONSE, partitioned by circle_visible_at_click.
        /** @var array<int,float> $signedAll */
        $signedAll     = [];
        $signedVisible = [];
        $signedHidden  = [];

        foreach ($trials as $trial) {
            $result = $trial['result'] ?? null;
            if ($result === 'FALSE_RESPONSE') {
                $falseCount++;
            } elseif ($result === 'MISSED_STIMULUS') {
                $missedCount++;
            }
            if ($result === 'TRUE_RESPONSE' && isset($trial['timing_error_ms']) && is_numeric($trial['timing_error_ms'])) {
                $err = (float) $trial['timing_error_ms'];
                $signedAll[] = $err;
                $visible = $trial['circle_visible_at_click'] ?? null;
                if ($visible === true) {
                    $signedVisible[] = $err;
                } elseif ($visible === false) {
                    $signedHidden[] = $err;
                }
            }
        }

        $timingStats = self::timingErrorStats($signedAll);
        $primaryMetricMs = $timingStats['mean_abs'] ?? 0.0;
        $sdMs = $timingStats['sd'] ?? null;

        $summary = [
            'timing_error_ms'      => $timingStats,
            'false_response_rate'  => $total > 0 ? $falseCount / $total : null,
            'missed_stimulus_rate' => $total > 0 ? $missedCount / $total : null,
            'true_response_count'  => count($signedAll),
            'total_trials'         => $total,
        ];

        // Report per-visibility breakdown only if both states occurred.
        if ($signedVisible !== [] && $signedHidden !== []) {
            $summary['visible_circle']  = self::timingErrorStats($signedVisible);
            $summary['hidden_circle']   = self::timingErrorStats($signedHidden);
        }

        return [
            'summary' => $summary,
            'primaryMetricMs' => $primaryMetricMs,
            'sdMs' => $sdMs,
            'cv' => self::coefficientOfVariation($sdMs, $primaryMetricMs === 0.0 ? null : $primaryMetricMs),
        ];
    }

    /**
     * Computes full descriptive stats for a set of values: mean, median, sd, P10/P25/P50/P75/P90.
     * @param array<int,float> $values
     * @return array<string,?float>
     */
    private static function fullStats(array $values): array
    {
        if ($values === []) {
            return [
                'count' => 0,
                'mean' => null, 'median' => null, 'sd' => null,
                'p10' => null, 'p25' => null, 'p50' => null, 'p75' => null, 'p90' => null,
                'min' => null, 'max' => null,
            ];
        }
        sort($values);
        $mean   = self::mean($values);
        $sd     = self::stddev($values);
        return [
            'count'  => count($values),
            'mean'   => $mean,
            'median' => self::median($values),
            'sd'     => $sd,
            'p10'    => self::percentile($values, 10),
            'p25'    => self::percentile($values, 25),
            'p50'    => self::percentile($values, 50),
            'p75'    => self::percentile($values, 75),
            'p90'    => self::percentile($values, 90),
            'min'    => min($values),
            'max'    => max($values),
        ];
    }

    /**
     * Computes signed timing-error KPIs: mean_signed, mean_abs, median_abs, sd, early_pct, late_pct.
     * @param array<int,float> $signed Signed timing error values (ms).
     * @return array<string,?float>
     */
    private static function timingErrorStats(array $signed): array
    {
        if ($signed === []) {
            return [
                'count' => 0,
                'mean_signed' => null, 'mean_abs' => null, 'median_abs' => null, 'sd' => null,
                'early_pct' => null, 'late_pct' => null,
            ];
        }
        $n     = count($signed);
        $abs   = array_map('abs', $signed);
        $early = count(array_filter($signed, static fn (float $v): bool => $v < 0));
        $late  = count(array_filter($signed, static fn (float $v): bool => $v > 0));
        return [
            'count'       => $n,
            'mean_signed' => self::mean($signed),
            'mean_abs'    => self::mean($abs),
            'median_abs'  => self::median($abs),
            'sd'          => self::stddev($signed),
            'early_pct'   => round($early / $n * 100, 1),
            'late_pct'    => round($late / $n * 100, 1),
        ];
    }

    /**
     * Linear-interpolation percentile (nearest-rank for whole-array; interpolated between
     * neighbours for fractional positions). Works on a pre-sorted array.
     * @param array<int,float> $sorted Pre-sorted values.
     */
    private static function percentile(array $sorted, float $p): ?float
    {
        $n = count($sorted);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        // Using the "exclusive" method: rank = p/100 * (n-1), then linear interpolation.
        $rank  = ($p / 100) * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return $sorted[$lower];
        }
        $frac = $rank - $lower;
        return $sorted[$lower] * (1 - $frac) + $sorted[$upper] * $frac;
    }

    /**
     * CR-TEST-28: the min/max fields always reflect the genuinely signed extremes of the raw
     * per-trial values, regardless of $absValueAggregation — so a user can still see their
     * earliest/latest anticipation alongside the (possibly abs-value) mean/sd. Key name is
     * `min`/`max` (unsuffixed) when abs-value aggregation is active — "ms" would be misleading for
     * what's actually a signed px distance for Circle Collision — and `min_ms`/`max_ms` (the
     * original, unit-suffixed names) for every other test type, completely unchanged.
     * @param array<int,float> $signedValues
     * @return array<string,?float>
     */
    private static function signedExtremes(array $signedValues, bool $absValueAggregation): array
    {
        $min = $signedValues === [] ? null : min($signedValues);
        $max = $signedValues === [] ? null : max($signedValues);
        return $absValueAggregation
            ? ['min' => $min, 'max' => $max]
            : ['min_ms' => $min, 'max_ms' => $max];
    }

    /** @param array<int,float> $values */
    private static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        return array_sum($values) / count($values);
    }

    /**
     * Sample standard deviation (n-1 denominator) — the standard unbiased estimator when the
     * values are a sample of a user's possible attempts, not the full population. Requires >=2
     * values; a single-valid-trial submission has no meaningful spread, so this returns null
     * rather than a fabricated 0.0 (0.0 would misleadingly imply "perfectly consistent").
     * @param array<int,float> $values
     */
    private static function stddev(array $values): ?float
    {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($values) / $n;
        $sumSquaredDiffs = array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $values));
        return sqrt($sumSquaredDiffs / ($n - 1));
    }

    /**
     * Coefficient of variation = sd / mean — a scale-free measure of relative variability, useful
     * for comparing consistency across users/tests with different average reaction times. Null
     * whenever either input is null, or when mean is exactly 0 (division would be undefined/
     * meaningless, not a real "perfectly variable" signal).
     */
    private static function coefficientOfVariation(?float $sdMs, ?float $meanMs): ?float
    {
        if ($sdMs === null || $meanMs === null || $meanMs === 0.0) {
            return null;
        }
        return $sdMs / $meanMs;
    }

    /** @param array<int,float> $values */
    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return $values[$mid];
    }
}
