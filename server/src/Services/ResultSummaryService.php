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
