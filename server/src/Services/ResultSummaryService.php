<?php

declare(strict_types=1);

namespace Reflexometr\Services;

/**
 * Computes a stored summary + a single generic "primary metric" (mean reaction time in ms,
 * lower = faster) from a validated trial log. The primary metric is what StatsService ranks
 * percentiles on — kept generic (not per-r-test-type bespoke code) so a newly imported r-test
 * needs no new server code to participate in stats (in the spirit of D11: this project only
 * provides a generic runtime).
 */
final class ResultSummaryService
{
    /**
     * @param array<string,mixed> $schedule Compiled schedule (has response_channels).
     * @param array<int,array<string,mixed>> $trials Validated submitted trial log.
     * @return array{summary: array<string,mixed>, primaryMetricMs: float}
     */
    public static function compute(array $schedule, array $trials, ?string $dominantHand): array
    {
        $channels = $schedule['response_channels'];
        /** @var array<string,array<int,float>> $perChannelTimes */
        $perChannelTimes = array_fill_keys($channels, []);
        $allTimes = [];
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
                $perChannelTimes[$channel][] = $rt;
                $allTimes[] = $rt;
            }
        }

        $channelStats = [];
        foreach ($channels as $channel) {
            $times = $perChannelTimes[$channel];
            $channelStats[$channel] = [
                'mean_ms' => self::mean($times),
                'median_ms' => self::median($times),
                'min_ms' => $times === [] ? null : min($times),
                'max_ms' => $times === [] ? null : max($times),
                'timeouts' => $timeoutCounts[$channel],
                'valid_count' => count($times),
            ];
        }

        $summary = [
            'overall' => [
                'mean_ms' => self::mean($allTimes),
                'median_ms' => self::median($allTimes),
                'valid_count' => count($allTimes),
            ],
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

        $primaryMetricMs = self::mean($allTimes) ?? 0.0;

        return ['summary' => $summary, 'primaryMetricMs' => $primaryMetricMs];
    }

    /** @param array<int,float> $values */
    private static function mean(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        return array_sum($values) / count($values);
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
