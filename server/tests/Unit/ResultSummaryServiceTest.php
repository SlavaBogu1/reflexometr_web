<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reflexometr\Services\ResultSummaryService;

final class ResultSummaryServiceTest extends TestCase
{
    public function testSingleChannelMeanReactionTime(): void
    {
        $schedule = ['response_channels' => ['primary']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 1200]], // 200ms
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3300]], // 300ms
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(250.0, $result['primaryMetricMs']);
        self::assertSame(250.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(2, $result['summary']['overall']['valid_count']);
    }

    public function testTwoHandDominantMinusNonDominantIsNegativeWhenDominantFaster(): void
    {
        $schedule = ['response_channels' => ['left', 'right']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['left' => 1200, 'right' => 1400]], // left 200, right 400
        ];

        // Dominant hand = left, and left is faster (200 < 400) -> negative delta.
        $result = ResultSummaryService::compute($schedule, $trials, 'left');

        self::assertSame(200.0, $result['summary']['channels']['left']['mean_ms']);
        self::assertSame(400.0, $result['summary']['channels']['right']['mean_ms']);
        self::assertSame(-200.0, $result['summary']['dominant_minus_nondominant_ms']);
    }

    public function testTimeoutsAreExcludedFromMeanButCounted(): void
    {
        $schedule = ['response_channels' => ['left', 'right']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['left' => 1200, 'right' => null]],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(0, $result['summary']['channels']['right']['valid_count']);
        self::assertSame(1, $result['summary']['channels']['right']['timeouts']);
        self::assertSame(200.0, $result['summary']['channels']['left']['mean_ms']);
    }

    public function testNoDominantHandOmitsDeltaField(): void
    {
        $schedule = ['response_channels' => ['left', 'right']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['left' => 1200, 'right' => 1400]],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, 'none-recorded');

        self::assertArrayNotHasKey('dominant_minus_nondominant_ms', $result['summary']);
    }
}
