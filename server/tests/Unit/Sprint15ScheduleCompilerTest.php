<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reflexometr\Http\ApiException;
use Reflexometr\Services\ScheduleCompiler;

/**
 * Sprint 15 (K6): ScheduleCompiler validation tests for the visual-conflict custom-KPI test type.
 */
final class Sprint15ScheduleCompilerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // SI-15.2 — CR-TEST-33: visual-conflict validation
    // -------------------------------------------------------------------------

    public function testVisualConflictValidDescriptionCompiles(): void
    {
        $desc = [
            'trial_count'                => 20,
            'pretrain_trial_count'       => 10,
            'phase_transition_display_ms' => 3000,
            'condition_ratio'            => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
            'response_window_ms'         => 2000,
            'inter_trial_interval_ms'    => 500,
            'randomize_delay_range_ms'   => ['min' => 500, 'max' => 2000],
            'shape_size_px'              => 80,
        ];

        $schedule = ScheduleCompiler::compile($desc);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertSame(20, $schedule['trial_count']);
        self::assertArrayHasKey('condition_ratio', $schedule);
        self::assertArrayNotHasKey('trials', $schedule);
    }

    public function testVisualConflictSeedFileCompilesWithoutError(): void
    {
        $path = __DIR__ . '/../../database/seeds/visual-conflict.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'visual-conflict.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertArrayHasKey('condition_ratio', $schedule);
    }

    // --- condition_ratio sum check ---

    public function testVisualConflictConditionRatioWithinToleranceAccepted(): void
    {
        // Values sum to 1.005 — within ±0.01 tolerance (deviation = 0.005), should pass.
        ScheduleCompiler::validateDescription([
            'trial_count'          => 10,
            'pretrain_trial_count' => 5,
            'condition_ratio'      => ['neutral' => 0.34, 'congruent' => 0.33, 'conflict' => 0.335],
            'response_window_ms'   => 2000,
        ]);
        self::assertTrue(true, 'Sum ~1.005 is within ±0.01 tolerance — should not throw');
    }

    public function testVisualConflictConditionRatioSumExactlyOnePointZeroTolerance(): void
    {
        // Exact sum = 1.0.
        ScheduleCompiler::validateDescription([
            'trial_count'          => 10,
            'pretrain_trial_count' => 5,
            'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
            'response_window_ms'   => 2000,
        ]);
        self::assertTrue(true, 'Sum 1.0 should pass');
    }

    public function testVisualConflictRejectsConditionRatioSumTooLow(): void
    {
        // Values sum to 0.90 — outside ±0.01 tolerance.
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.30, 'congruent' => 0.30, 'conflict' => 0.30],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for condition_ratio sum != 1.0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('condition_ratio', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsConditionRatioSumTooHigh(): void
    {
        // Values sum to 1.10 — outside ±0.01 tolerance.
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.40, 'congruent' => 0.40, 'conflict' => 0.30],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for condition_ratio sum > 1.01');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('condition_ratio', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsMissingConditionRatioKey(): void
    {
        // Missing "conflict" key.
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.5, 'congruent' => 0.5],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for missing condition key');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('condition_ratio', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsNonArrayConditionRatio(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => 'equal',
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for non-array condition_ratio');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('condition_ratio', $e->details()['fields'] ?? []);
        }
    }

    // --- pretrain_trial_count bounds ---

    public function testVisualConflictRejectsPretrainCountZero(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 0,  // must be >= 1
                'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for pretrain_trial_count = 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('pretrain_trial_count', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsPretrainCountNegative(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => -1,
                'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for pretrain_trial_count < 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('pretrain_trial_count', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictAcceptsPretrainCountOne(): void
    {
        ScheduleCompiler::validateDescription([
            'trial_count'          => 10,
            'pretrain_trial_count' => 1,  // minimum valid value
            'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
            'response_window_ms'   => 2000,
        ]);
        self::assertTrue(true, 'pretrain_trial_count = 1 should be accepted');
    }

    // --- trial_count bounds (reuses MAX_TRIAL_COUNT check from shared helper) ---

    public function testVisualConflictRejectsZeroTrialCount(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 0,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for trial_count = 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('trial_count', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsOverMaxTrialCount(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => ScheduleCompiler::MAX_TRIAL_COUNT + 1,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'   => 2000,
            ]);
            self::fail('Expected ApiException for trial_count > MAX_TRIAL_COUNT');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('trial_count', $e->details()['fields'] ?? []);
        }
    }

    // --- ms field non-negative checks ---

    public function testVisualConflictRejectsNegativeResponseWindowMs(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'          => 10,
                'pretrain_trial_count' => 5,
                'condition_ratio'      => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'   => 0,  // must be > 0
            ]);
            self::fail('Expected ApiException for response_window_ms = 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('response_window_ms', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsNegativeInterTrialIntervalMs(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'              => 10,
                'pretrain_trial_count'     => 5,
                'condition_ratio'          => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'       => 2000,
                'inter_trial_interval_ms'  => -1,  // must be >= 0
            ]);
            self::fail('Expected ApiException for inter_trial_interval_ms < 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('inter_trial_interval_ms', $e->details()['fields'] ?? []);
        }
    }

    public function testVisualConflictRejectsNegativePhaseTransitionDisplayMs(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count'                 => 10,
                'pretrain_trial_count'        => 5,
                'condition_ratio'             => ['neutral' => 0.33, 'congruent' => 0.33, 'conflict' => 0.34],
                'response_window_ms'          => 2000,
                'phase_transition_display_ms' => -1,  // must be >= 0
            ]);
            self::fail('Expected ApiException for phase_transition_display_ms < 0');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertContains('phase_transition_display_ms', $e->details()['fields'] ?? []);
        }
    }

    // --- Regression: classic and Sprint 14 test types still unaffected ---

    public function testClassicSimpleReactionUnaffected(): void
    {
        $path = __DIR__ . '/../../database/seeds/simple-reaction.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        $schedule = ScheduleCompiler::compile($description);

        self::assertArrayNotHasKey('schedule_family', $schedule);
        self::assertArrayHasKey('trials', $schedule);
    }

    public function testSprint14TemporalPredictionUnaffected(): void
    {
        $path = __DIR__ . '/../../database/seeds/temporal-prediction.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        $schedule = ScheduleCompiler::compile($description);

        self::assertSame('custom-kpi', $schedule['schedule_family']);
        self::assertArrayHasKey('circle_speed_px_per_ms', $schedule);
    }
}
