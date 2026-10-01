<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reflexometr\Http\ApiException;
use Reflexometr\Services\ScheduleCompiler;

/**
 * Sprint 14 (K3/K5/K7/K8): ScheduleCompiler validation tests for the four new custom-KPI test
 * families. Each test type uses its own discriminating field to trigger the new validation path.
 */
final class Sprint14ScheduleCompilerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // SI-14.1 — CR-TEST-30: random-target-pointing
    // -------------------------------------------------------------------------

    public function testRandomTargetPointingValidDescriptionCompiles(): void
    {
        $desc = [
            'trial_count' => 15,
            'target_diameter_px' => 80,
            'min_distance_from_prev_px' => 100,
            'response_timeout_ms' => 3000,
            'inter_trial_interval_ms' => 500,
            'randomize_delay_range_ms' => ['min' => 500, 'max' => 2000],
            'record_trajectory' => false,
        ];

        $schedule = ScheduleCompiler::compile($desc);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertSame(15, $schedule['trial_count']);
        self::assertSame(80, $schedule['target_diameter_px']);
        // Compiled schedule for custom-KPI carries description fields verbatim.
        self::assertArrayNotHasKey('trials', $schedule);
    }

    public function testRandomTargetPointingSeedFileCompilesWithoutError(): void
    {
        $path = __DIR__ . '/../../database/seeds/random-target-pointing.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'random-target-pointing.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertArrayHasKey('trial_count', $schedule);
        self::assertArrayHasKey('target_diameter_px', $schedule);
    }

    public function testRandomTargetPointingRejectsZeroTrialCount(): void
    {
        $this->expectException(ApiException::class);
        ScheduleCompiler::validateDescription([
            'trial_count' => 0,
            'target_diameter_px' => 80,
            'response_timeout_ms' => 3000,
        ]);
    }

    public function testRandomTargetPointingRejectsOverMaxTrialCount(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT + 1,
                'target_diameter_px' => 80,
                'response_timeout_ms' => 3000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('trial_count', $e->details()['fields'] ?? []);
        }
    }

    public function testRandomTargetPointingRejectsZeroDiameter(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'target_diameter_px' => 0,
                'response_timeout_ms' => 3000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('target_diameter_px', $e->details()['fields'] ?? []);
        }
    }

    public function testRandomTargetPointingRejectsNegativeMinDistance(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'target_diameter_px' => 80,
                'min_distance_from_prev_px' => -1,
                'response_timeout_ms' => 3000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('min_distance_from_prev_px', $e->details()['fields'] ?? []);
        }
    }

    public function testRandomTargetPointingRejectsInvalidDelayRange(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'target_diameter_px' => 80,
                'response_timeout_ms' => 3000,
                'randomize_delay_range_ms' => ['min' => 2000, 'max' => 500], // inverted
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('randomize_delay_range_ms', $e->details()['fields'] ?? []);
        }
    }

    public function testRandomTargetPointingRejectsNonBoolRecordTrajectory(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'target_diameter_px' => 80,
                'response_timeout_ms' => 3000,
                'record_trajectory' => 'yes', // not a bool
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('record_trajectory', $e->details()['fields'] ?? []);
        }
    }

    // -------------------------------------------------------------------------
    // SI-14.3 — CR-TEST-32: choice-reaction-geometry
    // -------------------------------------------------------------------------

    public function testChoiceReactionGeometryValidDescriptionCompiles(): void
    {
        $desc = [
            'trial_count' => 20,
            'shapes' => ['triangle', 'circle'],
            'key_mapping' => ['triangle' => 'ArrowLeft', 'circle' => 'ArrowRight'],
            'response_window_ms' => 2000,
            'inter_trial_interval_ms' => 500,
            'randomize_delay_range_ms' => ['min' => 500, 'max' => 2000],
            'shape_size_px' => 80,
            'show_mapping_during_measurement' => true,
        ];

        $schedule = ScheduleCompiler::compile($desc);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertSame(['triangle', 'circle'], $schedule['shapes']);
        self::assertArrayNotHasKey('trials', $schedule);
    }

    public function testChoiceReactionGeometrySeedFileCompilesWithoutError(): void
    {
        $path = __DIR__ . '/../../database/seeds/choice-reaction-geometry.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'choice-reaction-geometry.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
    }

    public function testChoiceReactionGeometryRejectsEmptyShapes(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'shapes' => [],
                'key_mapping' => [],
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('shapes', $e->details()['fields'] ?? []);
        }
    }

    public function testChoiceReactionGeometryRejectsUnknownShape(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'shapes' => ['triangle', 'hexagon'], // hexagon not in known set
                'key_mapping' => ['triangle' => 'a', 'hexagon' => 'b'],
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('shapes', $e->details()['fields'] ?? []);
        }
    }

    public function testChoiceReactionGeometryRejectsMissingKeyMappingEntry(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'shapes' => ['triangle', 'circle'],
                'key_mapping' => ['triangle' => 'a'], // missing 'circle'
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('key_mapping', $e->details()['fields'] ?? []);
        }
    }

    public function testChoiceReactionGeometryRejectsZeroResponseWindow(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'shapes' => ['triangle', 'circle'],
                'key_mapping' => ['triangle' => 'a', 'circle' => 'b'],
                'response_window_ms' => 0,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('response_window_ms', $e->details()['fields'] ?? []);
        }
    }

    // -------------------------------------------------------------------------
    // SI-14.5 — CR-TEST-34: peripheral-reaction
    // -------------------------------------------------------------------------

    public function testPeripheralReactionValidDescriptionCompiles(): void
    {
        $desc = [
            'trial_count' => 20,
            'positions' => [
                ['label' => 'left',  'angle_deg' => 180, 'eccentricity_px' => 200],
                ['label' => 'right', 'angle_deg' => 0,   'eccentricity_px' => 200],
            ],
            'stimulus_diameter_px' => 40,
            'response_window_ms' => 2000,
            'inter_trial_interval_ms' => 500,
            'randomize_delay_range_ms' => ['min' => 500, 'max' => 2000],
            'response_type' => 'key',
        ];

        $schedule = ScheduleCompiler::compile($desc);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertCount(2, $schedule['positions']);
    }

    public function testPeripheralReactionSeedFileCompilesWithoutError(): void
    {
        $path = __DIR__ . '/../../database/seeds/peripheral-reaction.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'peripheral-reaction.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
    }

    public function testPeripheralReactionRejectsEmptyPositions(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'positions' => [],
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('positions', $e->details()['fields'] ?? []);
        }
    }

    public function testPeripheralReactionRejectsOutOfRangeAngle(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'positions' => [
                    ['angle_deg' => 400, 'eccentricity_px' => 200], // out of range
                ],
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('positions', $e->details()['fields'] ?? []);
        }
    }

    public function testPeripheralReactionRejectsNonPositiveEccentricity(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'positions' => [
                    ['angle_deg' => 90, 'eccentricity_px' => 0], // zero not allowed
                ],
                'response_window_ms' => 2000,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('positions', $e->details()['fields'] ?? []);
        }
    }

    public function testPeripheralReactionRejectsInvalidResponseType(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'positions' => [['angle_deg' => 90, 'eccentricity_px' => 200]],
                'response_window_ms' => 2000,
                'response_type' => 'touch', // not "key" or "click"
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('response_type', $e->details()['fields'] ?? []);
        }
    }

    public function testPeripheralReactionAcceptsAngleZeroAndAngle360(): void
    {
        // angle_deg = 0 and angle_deg = 360 are both valid boundary values.
        ScheduleCompiler::validateDescription([
            'trial_count' => 4,
            'positions' => [
                ['angle_deg' => 0,   'eccentricity_px' => 200],
                ['angle_deg' => 360, 'eccentricity_px' => 200],
            ],
            'response_window_ms' => 2000,
        ]);
        self::assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // SI-14.7 — CR-TEST-35: temporal-prediction
    // -------------------------------------------------------------------------

    public function testTemporalPredictionValidDescriptionCompiles(): void
    {
        $desc = [
            'trial_count' => 15,
            'circle_speed_px_per_ms' => 0.3,
            'target_line_x_ratio' => 0.85,
            'start_x_ratio' => 0.05,
            'prediction_window_ms' => 200,
            'miss_tolerance_px' => 30,
            'disappear_before_target_px' => 0,
            'inter_trial_interval_ms' => 500,
            'circle_diameter_px' => 40,
        ];

        $schedule = ScheduleCompiler::compile($desc);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertSame(0.3, $schedule['circle_speed_px_per_ms']);
        self::assertArrayNotHasKey('trials', $schedule);
    }

    public function testTemporalPredictionSeedFileCompilesWithoutError(): void
    {
        $path = __DIR__ . '/../../database/seeds/temporal-prediction.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'temporal-prediction.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
    }

    public function testTemporalPredictionRejectsZeroSpeed(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'circle_speed_px_per_ms' => 0.0,
                'target_line_x_ratio' => 0.8,
                'start_x_ratio' => 0.1,
                'prediction_window_ms' => 200,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('circle_speed_px_per_ms', $e->details()['fields'] ?? []);
        }
    }

    public function testTemporalPredictionRejectsTargetRatioAtBoundary(): void
    {
        // target_line_x_ratio must be strictly in (0, 1) — not 0 or 1.
        foreach ([0.0, 1.0] as $bad) {
            try {
                ScheduleCompiler::validateDescription([
                    'trial_count' => 10,
                    'circle_speed_px_per_ms' => 0.3,
                    'target_line_x_ratio' => $bad,
                    'start_x_ratio' => 0.1,
                    'prediction_window_ms' => 200,
                ]);
                self::fail("Expected ApiException for target_line_x_ratio = $bad");
            } catch (ApiException $e) {
                self::assertSame(400, $e->status());
                self::assertContains('target_line_x_ratio', $e->details()['fields'] ?? []);
            }
        }
    }

    public function testTemporalPredictionRejectsStartRatioNotLessThanTargetRatio(): void
    {
        // start_x_ratio must be strictly < target_line_x_ratio.
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'circle_speed_px_per_ms' => 0.3,
                'target_line_x_ratio' => 0.5,
                'start_x_ratio' => 0.7, // greater than target
                'prediction_window_ms' => 200,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('start_x_ratio', $e->details()['fields'] ?? []);
        }
    }

    public function testTemporalPredictionRejectsStartRatioEqualToTargetRatio(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'circle_speed_px_per_ms' => 0.3,
                'target_line_x_ratio' => 0.5,
                'start_x_ratio' => 0.5, // equal — not strictly less
                'prediction_window_ms' => 200,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('start_x_ratio', $e->details()['fields'] ?? []);
        }
    }

    public function testTemporalPredictionRejectsNegativeDisappearDistance(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'circle_speed_px_per_ms' => 0.3,
                'target_line_x_ratio' => 0.8,
                'start_x_ratio' => 0.1,
                'prediction_window_ms' => 200,
                'disappear_before_target_px' => -5,
            ]);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('disappear_before_target_px', $e->details()['fields'] ?? []);
        }
    }

    // -------------------------------------------------------------------------
    // Regression: classic tests still validate and compile as before
    // -------------------------------------------------------------------------

    public function testClassicSimpleReactionDescriptionUnaffected(): void
    {
        $path = __DIR__ . '/../../database/seeds/simple-reaction.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        $schedule = ScheduleCompiler::compile($description);

        self::assertArrayNotHasKey('schedule_family', $schedule);
        self::assertArrayHasKey('trials', $schedule);
    }

    public function testClassicCircleCollisionSimpleUnaffected(): void
    {
        $path = __DIR__ . '/../../database/seeds/circle-collision-simple.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        $schedule = ScheduleCompiler::compile($description);

        self::assertArrayNotHasKey('schedule_family', $schedule);
        self::assertTrue($schedule['abs_value_aggregation']);
    }
}
