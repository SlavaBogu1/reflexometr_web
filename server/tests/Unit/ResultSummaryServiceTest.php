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

    /**
     * CR-TEST-23 (Sprint 11): coincidence-anticipation tests (Circle Collision) allow a response
     * BEFORE stimulus_at (an early anticipation — see RunService::validateTrialLog's
     * allow_early_response flag, which is what makes it past validation to reach this service at
     * all). This test verifies the actual arithmetic here — `$value - $stimulusAt` — has no
     * abs()/max(0, ...)/clamping anywhere that would silently discard the sign; a genuinely early
     * response must produce a genuinely negative reaction time in mean/median/min/max, not assume
     * it from reading the code once.
     */
    public function testEarlyAnticipationProducesNegativeReactionTime(): void
    {
        $schedule = ['response_channels' => ['primary']];
        $trials = [
            // Clicked 150ms BEFORE the resolved "stimulus" instant (early anticipation).
            ['index' => 0, 'stimulus_at' => 2000, 'responses' => ['primary' => 1850]],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(-150.0, $result['primaryMetricMs']);
        self::assertSame(-150.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(-150.0, $result['summary']['overall']['median_ms']);
        self::assertSame(-150.0, $result['summary']['channels']['primary']['mean_ms']);
        self::assertSame(-150.0, $result['summary']['channels']['primary']['min_ms']);
        self::assertSame(-150.0, $result['summary']['channels']['primary']['max_ms']);
    }

    /**
     * A mix of early (negative) and late (positive) anticipations across trials must average to a
     * genuinely signed mean that reflects both directions — not clamp negatives to 0 before
     * averaging, which would silently bias the mean upward and hide genuine early-anticipation
     * behavior from a user reviewing their own results.
     */
    public function testMixedEarlyAndLateAnticipationsProduceCorrectlySignedMean(): void
    {
        $schedule = ['response_channels' => ['primary']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 800]],  // -200ms (early)
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3300]], // +300ms (late)
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        // Mean of -200 and +300 is +50, not (200+300)/2=250 — proves the sign survived averaging.
        self::assertSame(50.0, $result['primaryMetricMs']);
        self::assertSame(50.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(-200.0, $result['summary']['channels']['primary']['min_ms']);
        self::assertSame(300.0, $result['summary']['channels']['primary']['max_ms']);
        // sd_ms/cv are still computed normally on signed values (n=2, so non-null).
        self::assertNotNull($result['sdMs']);
    }
}
