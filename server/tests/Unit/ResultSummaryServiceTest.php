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

    // --- CR-TEST-28 (SI-13.4): abs_value_aggregation flag and its scoping ---

    /**
     * Regression test (explicit, not an absence-of-change assumption per SI-13.4's dispatch note):
     * every non-Circle-Collision test type's schedule never sets `abs_value_aggregation`, so this
     * confirms the exact same net-cancelling signed-mean behavior from
     * testMixedEarlyAndLateAnticipationsProduceCorrectlySignedMean above still holds when the flag
     * is entirely absent from the schedule (the real shape every existing test type's compiled
     * schedule has — ScheduleCompiler only ever adds the key for Circle Collision).
     */
    public function testSignedAverageIsUnaffectedWhenAbsValueAggregationFlagIsAbsent(): void
    {
        $schedule = ['response_channels' => ['primary']]; // no abs_value_aggregation key at all
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 800]],  // -200ms (early)
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3300]], // +300ms (late)
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        // Mean of -200 and +300 is +50, not the abs-value 250 — net-cancelling signed average,
        // exactly as documented in CR-TEST-23/24's v1.6 contract note, unreversed here.
        self::assertSame(50.0, $result['primaryMetricMs']);
        self::assertSame(50.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(-200.0, $result['summary']['overall']['min_ms']);
        self::assertSame(300.0, $result['summary']['overall']['max_ms']);
        self::assertArrayNotHasKey('min', $result['summary']['overall']);
        self::assertArrayNotHasKey('max', $result['summary']['overall']);
    }

    /**
     * Same regression, explicit `abs_value_aggregation: false` (belt-and-suspenders — a schedule
     * that explicitly opts out, not just omits the key, still gets the ordinary signed average).
     */
    public function testSignedAverageIsUnaffectedWhenAbsValueAggregationIsExplicitlyFalse(): void
    {
        $schedule = ['response_channels' => ['primary'], 'abs_value_aggregation' => false];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 800]],  // -200ms
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3300]], // +300ms
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(50.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(-200.0, $result['summary']['overall']['min_ms']);
        self::assertSame(300.0, $result['summary']['overall']['max_ms']);
    }

    /**
     * Two-hand-reaction regression (a real second test type, not just a synthetic single-channel
     * case): dominant_minus_nondominant_ms and per-channel signed means must be completely
     * unaffected by SI-13.4's change, since two-hand-reaction's schedule never sets
     * abs_value_aggregation.
     */
    public function testTwoHandSignedChannelMeansUnaffectedByAbsValueAggregationChange(): void
    {
        $schedule = ['response_channels' => ['left', 'right']]; // no abs_value_aggregation
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['left' => 800, 'right' => 1400]], // left -200, right +400
        ];

        $result = ResultSummaryService::compute($schedule, $trials, 'left');

        self::assertSame(-200.0, $result['summary']['channels']['left']['mean_ms']);
        self::assertSame(400.0, $result['summary']['channels']['right']['mean_ms']);
        // Dominant (left) minus non-dominant (right): -200 - 400 = -600, unaffected by abs-value.
        self::assertSame(-600.0, $result['summary']['dominant_minus_nondominant_ms']);
        self::assertSame(-200.0, $result['summary']['channels']['left']['min_ms']);
        self::assertSame(-200.0, $result['summary']['channels']['left']['max_ms']);
    }

    /** Circle-Collision-flagged schedule: abs-value mean/sd, but min/max stay genuinely signed. */
    public function testAbsValueAggregationFlagProducesAbsMeanButSignedMinMax(): void
    {
        $schedule = ['response_channels' => ['primary'], 'abs_value_aggregation' => true];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 800]],  // -200
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3300]], // +300
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        // abs(-200)=200, abs(300)=300 -> mean 250, NOT the signed +50.
        self::assertSame(250.0, $result['primaryMetricMs']);
        self::assertSame(250.0, $result['summary']['overall']['mean_ms']);
        // min/max still the genuinely signed extremes, unsuffixed field names.
        self::assertSame(-200.0, $result['summary']['overall']['min']);
        self::assertSame(300.0, $result['summary']['overall']['max']);
        self::assertArrayNotHasKey('min_ms', $result['summary']['overall']);
        self::assertArrayNotHasKey('max_ms', $result['summary']['overall']);
    }

    /** A null (timed-out) response is excluded from the abs-value mean the same way it's already
     * excluded from the signed mean for every other test type. */
    public function testAbsValueAggregationExcludesNullResponsesFromMean(): void
    {
        $schedule = ['response_channels' => ['primary'], 'abs_value_aggregation' => true];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 800]], // -200 -> abs 200
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => null]], // timed out
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(200.0, $result['summary']['overall']['mean_ms']);
        self::assertSame(1, $result['summary']['overall']['valid_count']);
        self::assertSame(1, $result['summary']['channels']['primary']['timeouts']);
    }
}
