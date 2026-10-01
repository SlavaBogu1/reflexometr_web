<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reflexometr\Services\ResultSummaryService;

/**
 * Sprint 14 (K3/K5/K7/K8): ResultSummaryService KPI computation tests for the four new custom-KPI
 * test families.
 */
final class Sprint14ResultSummaryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // SI-14.2 — CR-TEST-30: random-target-pointing KPIs
    // -------------------------------------------------------------------------

    private static function rtpSchedule(): array
    {
        return [
            'schedule_family' => 'custom-kpi',
            'trial_count' => 5,
            'target_diameter_px' => 80,
        ];
    }

    public function testRandomTargetPointingAggregatesCorrectTrialMetrics(): void
    {
        $schedule = self::rtpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 250, 'movement_time_ms' => 400, 'click_error_px' => 5],
            ['index' => 1, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 300, 'movement_time_ms' => 350, 'click_error_px' => 10],
            ['index' => 2, 'result' => 'FALSE_RESPONSE', 'reaction_time_ms' => null, 'movement_time_ms' => null, 'click_error_px' => null],
            ['index' => 3, 'result' => 'MISSED_STIMULUS','reaction_time_ms' => null, 'movement_time_ms' => null, 'click_error_px' => null],
            ['index' => 4, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 200, 'movement_time_ms' => 500, 'click_error_px' => 3],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        $summary = $result['summary'];
        self::assertSame(3, $summary['reaction_time_ms']['count']);
        self::assertSame(3, $summary['true_response_count']);
        self::assertSame(5, $summary['total_trials']);

        // RT stats are for TRUE_RESPONSE only.
        self::assertEqualsWithDelta(250.0, $summary['reaction_time_ms']['mean'], 0.01);
        self::assertEqualsWithDelta(250.0, $summary['reaction_time_ms']['median'], 0.01);

        // Rates.
        self::assertEqualsWithDelta(1 / 5, $summary['false_response_rate'], 0.01);
        self::assertEqualsWithDelta(1 / 5, $summary['missed_stimulus_rate'], 0.01);

        // Primary metric = median RT of TRUE_RESPONSE trials.
        self::assertEqualsWithDelta(250.0, $result['primaryMetricMs'], 0.01);
    }

    public function testRandomTargetPointingAllMissedReturnsNullMetric(): void
    {
        $schedule = self::rtpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'MISSED_STIMULUS', 'reaction_time_ms' => null, 'movement_time_ms' => null, 'click_error_px' => null],
            ['index' => 1, 'result' => 'MISSED_STIMULUS', 'reaction_time_ms' => null, 'movement_time_ms' => null, 'click_error_px' => null],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(0, $result['summary']['true_response_count']);
        self::assertNull($result['summary']['reaction_time_ms']['mean']);
        self::assertEqualsWithDelta(0.0, $result['primaryMetricMs'], 0.001);
    }

    public function testRandomTargetPointingNeverAveragesFalseResponseRt(): void
    {
        // FALSE_RESPONSE trials with non-null reaction_time_ms must not bleed into RT stats.
        $schedule = self::rtpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 200, 'movement_time_ms' => 300, 'click_error_px' => 5],
            ['index' => 1, 'result' => 'FALSE_RESPONSE', 'reaction_time_ms' => 9999, 'movement_time_ms' => 9999, 'click_error_px' => 9999],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        // Only 1 TRUE_RESPONSE — RT stats must reflect only the 200ms trial.
        self::assertSame(1, $result['summary']['reaction_time_ms']['count']);
        self::assertEqualsWithDelta(200.0, $result['summary']['reaction_time_ms']['mean'], 0.01);
        self::assertEqualsWithDelta(200.0, $result['primaryMetricMs'], 0.01);
    }

    public function testRandomTargetPointingFullStatsHavePercentiles(): void
    {
        $schedule = self::rtpSchedule();
        $trials = [];
        // 10 TRUE_RESPONSE trials with RT = 100..1000 ms (step 100).
        for ($i = 0; $i < 10; $i++) {
            $trials[] = [
                'index' => $i, 'result' => 'TRUE_RESPONSE',
                'reaction_time_ms' => ($i + 1) * 100,
                'movement_time_ms' => 300,
                'click_error_px' => 5,
            ];
        }

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $rtStats = $result['summary']['reaction_time_ms'];

        self::assertArrayHasKey('p10', $rtStats);
        self::assertArrayHasKey('p25', $rtStats);
        self::assertArrayHasKey('p50', $rtStats);
        self::assertArrayHasKey('p75', $rtStats);
        self::assertArrayHasKey('p90', $rtStats);
        self::assertNotNull($rtStats['p10']);
        self::assertNotNull($rtStats['p90']);
        // P50 of [100,200,...,1000] ≈ 550ms (midpoint between 500 and 600).
        self::assertEqualsWithDelta(550.0, $rtStats['p50'], 0.01);
    }

    // -------------------------------------------------------------------------
    // SI-14.4 — CR-TEST-32: choice-reaction-geometry KPIs
    // -------------------------------------------------------------------------

    private static function crgSchedule(): array
    {
        return [
            'schedule_family' => 'custom-kpi',
            'trial_count' => 5,
            'shapes' => ['triangle', 'circle'],
        ];
    }

    public function testChoiceReactionGeometryCorrectTrialRtOnly(): void
    {
        $schedule = self::crgSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 300, 'stimulus_shape' => 'circle',   'response_key' => 'ArrowRight'],
            ['index' => 1, 'result' => 'FALSE_RESPONSE', 'reaction_time_ms' => 200, 'stimulus_shape' => 'triangle', 'response_key' => 'ArrowRight'],
            ['index' => 2, 'result' => 'MISSED_STIMULUS','reaction_time_ms' => null, 'stimulus_shape' => 'circle',   'response_key' => null],
            ['index' => 3, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 400, 'stimulus_shape' => 'triangle', 'response_key' => 'ArrowLeft'],
            ['index' => 4, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 350, 'stimulus_shape' => 'circle',   'response_key' => 'ArrowRight'],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $summary = $result['summary'];

        // RT stats for correct trials only (3 correct).
        self::assertSame(3, $summary['reaction_time_ms']['count']);
        self::assertEqualsWithDelta((300 + 400 + 350) / 3, $summary['reaction_time_ms']['mean'], 0.01);

        // Rates.
        self::assertEqualsWithDelta(3 / 5, $summary['correct_response_rate'], 0.01);
        self::assertEqualsWithDelta(1 / 5, $summary['wrong_response_rate'], 0.01);
        self::assertEqualsWithDelta(1 / 5, $summary['missed_response_rate'], 0.01);

        // Primary = mean correct RT.
        self::assertEqualsWithDelta((300 + 400 + 350) / 3, $result['primaryMetricMs'], 0.01);
    }

    public function testChoiceReactionGeometryFalseResponseRtNeverInMean(): void
    {
        $schedule = self::crgSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 200, 'stimulus_shape' => 'circle', 'response_key' => 'ArrowRight'],
            ['index' => 1, 'result' => 'FALSE_RESPONSE', 'reaction_time_ms' => 9999, 'stimulus_shape' => 'triangle', 'response_key' => 'ArrowRight'],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertSame(1, $result['summary']['reaction_time_ms']['count']);
        self::assertEqualsWithDelta(200.0, $result['primaryMetricMs'], 0.01);
    }

    // -------------------------------------------------------------------------
    // SI-14.6 — CR-TEST-34: peripheral-reaction KPIs
    // -------------------------------------------------------------------------

    private static function prSchedule(): array
    {
        return [
            'schedule_family' => 'custom-kpi',
            'trial_count' => 8,
            'positions' => [
                ['label' => 'left',  'angle_deg' => 180, 'eccentricity_px' => 200],
                ['label' => 'right', 'angle_deg' => 0,   'eccentricity_px' => 200],
            ],
        ];
    }

    public function testPeripheralReactionOverallRtStats(): void
    {
        $schedule = self::prSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 250, 'stimulus_position_label' => 'left'],
            ['index' => 1, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 300, 'stimulus_position_label' => 'right'],
            ['index' => 2, 'result' => 'MISSED_STIMULUS','reaction_time_ms' => null, 'stimulus_position_label' => 'left'],
            ['index' => 3, 'result' => 'TRUE_RESPONSE',  'reaction_time_ms' => 200, 'stimulus_position_label' => 'right'],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $summary = $result['summary'];

        self::assertSame(3, $summary['reaction_time_ms']['count']);
        self::assertEqualsWithDelta((250 + 300 + 200) / 3, $summary['reaction_time_ms']['mean'], 0.01);
        self::assertEqualsWithDelta(1 / 4, $summary['missed_stimulus_rate'], 0.01);

        // Primary = mean overall RT.
        self::assertEqualsWithDelta((250 + 300 + 200) / 3, $result['primaryMetricMs'], 0.01);
    }

    public function testPeripheralReactionPerPositionAppearsWhenAtLeast3Trials(): void
    {
        $schedule = self::prSchedule();
        // 4 trials at 'left', 2 trials at 'right' — only 'left' gets per-position stats.
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 200, 'stimulus_position_label' => 'left'],
            ['index' => 1, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 250, 'stimulus_position_label' => 'left'],
            ['index' => 2, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 300, 'stimulus_position_label' => 'left'],
            ['index' => 3, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 350, 'stimulus_position_label' => 'left'],
            ['index' => 4, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 400, 'stimulus_position_label' => 'right'],
            ['index' => 5, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 450, 'stimulus_position_label' => 'right'],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $summary = $result['summary'];

        self::assertArrayHasKey('per_position', $summary);
        self::assertArrayHasKey('left', $summary['per_position']);
        self::assertArrayNotHasKey('right', $summary['per_position']); // only 2 trials, below threshold
        self::assertSame(4, $summary['per_position']['left']['count']);
    }

    public function testPeripheralReactionPerPositionAbsentWhenBelowThreshold(): void
    {
        $schedule = self::prSchedule();
        // All positions have < 3 TRUE_RESPONSE trials.
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 200, 'stimulus_position_label' => 'left'],
            ['index' => 1, 'result' => 'TRUE_RESPONSE', 'reaction_time_ms' => 300, 'stimulus_position_label' => 'right'],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertArrayNotHasKey('per_position', $result['summary']);
    }

    // -------------------------------------------------------------------------
    // SI-14.8 — CR-TEST-35: temporal-prediction KPIs
    // -------------------------------------------------------------------------

    private static function tpSchedule(): array
    {
        return [
            'schedule_family' => 'custom-kpi',
            'trial_count' => 6,
            'circle_speed_px_per_ms' => 0.3,
        ];
    }

    public function testTemporalPredictionSignedTimingErrorStats(): void
    {
        $schedule = self::tpSchedule();
        // 3 early (negative), 2 late (positive), 1 exact.
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => -100, 'circle_visible_at_click' => true],
            ['index' => 1, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => -50,  'circle_visible_at_click' => true],
            ['index' => 2, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 0,    'circle_visible_at_click' => true],
            ['index' => 3, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 100,  'circle_visible_at_click' => true],
            ['index' => 4, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 50,   'circle_visible_at_click' => true],
            ['index' => 5, 'result' => 'MISSED_STIMULUS','timing_error_ms' => null, 'circle_visible_at_click' => null],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $timing = $result['summary']['timing_error_ms'];

        // mean_signed = (-100-50+0+100+50)/5 = 0
        self::assertEqualsWithDelta(0.0, $timing['mean_signed'], 0.01);
        // mean_abs = (100+50+0+100+50)/5 = 60
        self::assertEqualsWithDelta(60.0, $timing['mean_abs'], 0.01);
        // median_abs of [0,50,50,100,100] = 50
        self::assertEqualsWithDelta(50.0, $timing['median_abs'], 0.01);
        // early_pct: 2/5 = 40%
        self::assertEqualsWithDelta(40.0, $timing['early_pct'], 0.01);
        // late_pct: 2/5 = 40%
        self::assertEqualsWithDelta(40.0, $timing['late_pct'], 0.01);

        // Primary = mean_abs.
        self::assertEqualsWithDelta(60.0, $result['primaryMetricMs'], 0.01);
    }

    public function testTemporalPredictionPerVisibilityStatsAppearWhenBothOccur(): void
    {
        $schedule = self::tpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => -100, 'circle_visible_at_click' => true],
            ['index' => 1, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 50,   'circle_visible_at_click' => true],
            ['index' => 2, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 200,  'circle_visible_at_click' => false],
            ['index' => 3, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 150,  'circle_visible_at_click' => false],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);
        $summary = $result['summary'];

        self::assertArrayHasKey('visible_circle', $summary);
        self::assertArrayHasKey('hidden_circle', $summary);
        self::assertSame(2, $summary['visible_circle']['count']);
        self::assertSame(2, $summary['hidden_circle']['count']);
    }

    public function testTemporalPredictionPerVisibilityAbsentWhenOnlyOneState(): void
    {
        $schedule = self::tpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => -100, 'circle_visible_at_click' => true],
            ['index' => 1, 'result' => 'TRUE_RESPONSE', 'timing_error_ms' => 50,   'circle_visible_at_click' => true],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertArrayNotHasKey('visible_circle', $result['summary']);
        self::assertArrayNotHasKey('hidden_circle', $result['summary']);
    }

    public function testTemporalPredictionMissedTrialsExcludedFromTimingStats(): void
    {
        $schedule = self::tpSchedule();
        $trials = [
            ['index' => 0, 'result' => 'TRUE_RESPONSE',  'timing_error_ms' => -50,  'circle_visible_at_click' => true],
            ['index' => 1, 'result' => 'MISSED_STIMULUS','timing_error_ms' => null, 'circle_visible_at_click' => null],
            ['index' => 2, 'result' => 'FALSE_RESPONSE', 'timing_error_ms' => 9999, 'circle_visible_at_click' => false],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        // Only 1 TRUE_RESPONSE trial (index 0) should count.
        self::assertSame(1, $result['summary']['timing_error_ms']['count']);
        self::assertEqualsWithDelta(50.0, $result['summary']['timing_error_ms']['mean_abs'], 0.01);
    }

    // -------------------------------------------------------------------------
    // Regression: classic test types still aggregate via the existing path
    // -------------------------------------------------------------------------

    public function testClassicScheduleNotDispatchedToCustomKpiPath(): void
    {
        // A classic schedule (no schedule_family) must still produce the old mean_ms/channels shape.
        $schedule = ['response_channels' => ['primary']];
        $trials = [
            ['index' => 0, 'stimulus_at' => 1000, 'responses' => ['primary' => 1250]],
            ['index' => 1, 'stimulus_at' => 3000, 'responses' => ['primary' => 3350]],
        ];

        $result = ResultSummaryService::compute($schedule, $trials, null);

        self::assertArrayHasKey('channels', $result['summary']);
        self::assertArrayHasKey('overall', $result['summary']);
        self::assertEqualsWithDelta(300.0, $result['primaryMetricMs'], 0.01);
    }
}
