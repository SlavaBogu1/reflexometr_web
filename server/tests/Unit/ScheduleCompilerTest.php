<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reflexometr\Http\ApiException;
use Reflexometr\Services\ScheduleCompiler;

final class ScheduleCompilerTest extends TestCase
{
    public function testCompileProducesResolvedTrialsWithinRange(): void
    {
        $description = [
            'trial_count' => 10,
            'inter_stimulus_delay_ms' => ['min' => 1000, 'max' => 3000],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ];

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame(10, $schedule['trial_count']);
        self::assertSame(ScheduleCompiler::DEFAULT_FALSE_START_BUFFER, $schedule['buffer_trials']);
        self::assertSame(['primary'], $schedule['response_channels']);
        self::assertNull($schedule['timeout_ms']);
        // trials[] over-provisions trial_count + buffer_trials resolved delays (false-start buffer).
        self::assertCount(10 + ScheduleCompiler::DEFAULT_FALSE_START_BUFFER, $schedule['trials']);
        foreach ($schedule['trials'] as $i => $trial) {
            self::assertSame($i, $trial['index']);
            self::assertGreaterThanOrEqual(1000, $trial['delay_ms']);
            self::assertLessThanOrEqual(3000, $trial['delay_ms']);
        }

        // Never leaks the raw parameter range itself as a top-level field.
        self::assertArrayNotHasKey('inter_stimulus_delay_ms', $schedule);
    }

    public function testFalseStartBufferCanBeOverriddenByDescription(): void
    {
        $description = [
            'trial_count' => 4,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
            'false_start_buffer' => 2,
        ];

        $schedule = ScheduleCompiler::compile($description);

        self::assertSame(2, $schedule['buffer_trials']);
        self::assertCount(6, $schedule['trials']);
    }

    public function testCompileIsRandomizedAcrossTrials(): void
    {
        $description = [
            'trial_count' => 20,
            'inter_stimulus_delay_ms' => ['min' => 500, 'max' => 5000],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ];
        $schedule = ScheduleCompiler::compile($description);
        $delays = array_column($schedule['trials'], 'delay_ms');

        // Not every value identical — extremely unlikely with 20 draws from a wide range if the
        // compiler is actually randomizing (guards against an accidental constant/no-op compiler).
        self::assertGreaterThan(1, count(array_unique($delays)));
    }

    public function testValidateDescriptionRejectsMissingTrialCount(): void
    {
        $this->expectException(ApiException::class);
        ScheduleCompiler::validateDescription([
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
        ]);
    }

    public function testValidateDescriptionRejectsInvertedDelayRange(): void
    {
        $this->expectException(ApiException::class);
        ScheduleCompiler::validateDescription([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 500, 'max' => 100],
            'response_channels' => ['primary'],
        ]);
    }

    public function testValidateDescriptionRejectsEmptyChannels(): void
    {
        $this->expectException(ApiException::class);
        ScheduleCompiler::validateDescription([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => [],
        ]);
    }

    public function testValidateDescriptionAcceptsTwoHandShape(): void
    {
        // No exception == pass.
        ScheduleCompiler::validateDescription([
            'trial_count' => 10,
            'inter_stimulus_delay_ms' => ['min' => 1500, 'max' => 3500],
            'response_channels' => ['left', 'right'],
            'timeout_ms' => 2000,
        ]);
        self::assertTrue(true);
    }

    /** CR-TEST-27: trial_count above MAX_TRIAL_COUNT is rejected at import-validation time. */
    public function testValidateDescriptionRejectsOversizedTrialCount(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT + 1,
                'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
                'response_channels' => ['primary'],
                'timeout_ms' => null,
            ]);
            self::fail('Expected ApiException for oversized trial_count');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertSame(['trial_count'], $e->details()['fields'] ?? null);
        }
    }

    /** CR-TEST-27: a normal in-bound trial_count (matching real r-tests' actual values) is unaffected. */
    public function testValidateDescriptionAcceptsInBoundTrialCount(): void
    {
        ScheduleCompiler::validateDescription([
            'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ]);
        ScheduleCompiler::validateDescription([
            'trial_count' => 10,
            'inter_stimulus_delay_ms' => ['min' => 1000, 'max' => 3000],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ]);
        self::assertTrue(true);
    }

    /** CR-TEST-27: compile() rejects a normal (validated) in-bound trial_count exactly as before. */
    public function testCompileStillWorksForMaxTrialCount(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT,
            'inter_stimulus_delay_ms' => ['min' => 1, 'max' => 1],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
            'false_start_buffer' => 0,
        ]);
        self::assertSame(ScheduleCompiler::MAX_TRIAL_COUNT, $schedule['trial_count']);
        self::assertCount(ScheduleCompiler::MAX_TRIAL_COUNT, $schedule['trials']);
    }

    /**
     * CR-TEST-27 defense-in-depth: simulate a bypass of validateDescription() (e.g. a future code
     * path reaching compile() with an unvalidated description) by disabling that check via a
     * trial_count so large the iteration ceiling — not the import-time bound — is what must catch
     * it. Must fail safely with a caught ApiException/JSON-enveloped 500, never an uncaught PHP
     * Fatal. We exercise this directly by reflecting into the private compile() path is not
     * possible without bypassing validateDescription, so instead assert the ceiling constant
     * itself is comfortably above MAX_TRIAL_COUNT (the two layers are independent) and that
     * compile() on an already-validated, in-bound description never trips the ceiling.
     */
    public function testIterationCeilingExceedsMaxTrialCountByComfortableMargin(): void
    {
        $ref = new \ReflectionClass(ScheduleCompiler::class);
        $ceiling = $ref->getConstant('MAX_TRIAL_GENERATION_ITERATIONS');
        self::assertGreaterThan(ScheduleCompiler::MAX_TRIAL_COUNT, $ceiling);
    }

    /**
     * CR-TEST-27/CR-TEST-29: since MAX_FALSE_START_BUFFER (SI-13.1) now caps false_start_buffer at
     * import-validation time too, MAX_TRIAL_COUNT + MAX_FALSE_START_BUFFER combined can never reach
     * MAX_TRIAL_GENERATION_ITERATIONS through the public validateDescription()/compile() path any
     * more — the import-time checks always fire first. This asserts that comfortable margin holds
     * by construction (defense-in-depth ceiling remains a backstop against a bypass elsewhere in
     * the codebase, not something reachable via in-bound field values alone).
     */
    public function testMaxTrialCountAndMaxFalseStartBufferCombinedNeverReachIterationCeiling(): void
    {
        $ref = new \ReflectionClass(ScheduleCompiler::class);
        $ceiling = $ref->getConstant('MAX_TRIAL_GENERATION_ITERATIONS');

        $combined = ScheduleCompiler::MAX_TRIAL_COUNT + ScheduleCompiler::MAX_FALSE_START_BUFFER;
        self::assertLessThan($ceiling, $combined);

        // And the fully-in-bound combination genuinely compiles without tripping the ceiling.
        $schedule = ScheduleCompiler::compile([
            'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT,
            'inter_stimulus_delay_ms' => ['min' => 1, 'max' => 1],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
            'false_start_buffer' => ScheduleCompiler::MAX_FALSE_START_BUFFER,
        ]);
        self::assertCount($combined, $schedule['trials']);
    }

    /**
     * CR-TEST-27 defense-in-depth: the iteration-ceiling guard inside compile() remains a genuine
     * second layer, independent of validateDescription()'s import-time bounds — it must still fail
     * safely (ApiException/500, not an uncaught Fatal) if ever reached via a future bypass. We
     * cannot reach it through the public API anymore (by design, see the test above), so we assert
     * the ceiling constant itself, and confirm compile() enforces it via reflection-free black-box
     * means is not possible without bypassing validateDescription() — instead this documents the
     * invariant the ceiling constant must continue to satisfy.
     */
    public function testIterationCeilingConstantRemainsWellAboveCombinedBounds(): void
    {
        $ref = new \ReflectionClass(ScheduleCompiler::class);
        $ceiling = $ref->getConstant('MAX_TRIAL_GENERATION_ITERATIONS');
        self::assertGreaterThan(
            ScheduleCompiler::MAX_TRIAL_COUNT + ScheduleCompiler::MAX_FALSE_START_BUFFER,
            $ceiling,
        );
    }

    /** CR-TEST-29: false_start_buffer above MAX_FALSE_START_BUFFER is rejected at import-validation
     * time, same pattern as CR-TEST-27's trial_count check. */
    public function testValidateDescriptionRejectsOversizedFalseStartBuffer(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
                'response_channels' => ['primary'],
                'timeout_ms' => null,
                'false_start_buffer' => ScheduleCompiler::MAX_FALSE_START_BUFFER + 1,
            ]);
            self::fail('Expected ApiException for oversized false_start_buffer');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertSame(['false_start_buffer'], $e->details()['fields'] ?? null);
        }
    }

    /** CR-TEST-29: in-bound false_start_buffer values, including the max and the default (6), are
     * unaffected by the new upper bound. */
    public function testValidateDescriptionAcceptsInBoundFalseStartBuffer(): void
    {
        foreach ([0, ScheduleCompiler::DEFAULT_FALSE_START_BUFFER, ScheduleCompiler::MAX_FALSE_START_BUFFER] as $buffer) {
            ScheduleCompiler::validateDescription([
                'trial_count' => 10,
                'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
                'response_channels' => ['primary'],
                'timeout_ms' => null,
                'false_start_buffer' => $buffer,
            ]);
        }
        self::assertTrue(true);
    }

    /** CR-TEST-29: omitting false_start_buffer entirely (defaulting to DEFAULT_FALSE_START_BUFFER)
     * still compiles correctly and is unaffected by the new upper bound. */
    public function testCompileStillWorksWithDefaultFalseStartBuffer(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => 10,
            'inter_stimulus_delay_ms' => ['min' => 1, 'max' => 1],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ]);
        self::assertSame(ScheduleCompiler::DEFAULT_FALSE_START_BUFFER, $schedule['buffer_trials']);
    }

    /** CR-TEST-27 regression: MAX_TRIAL_COUNT's own bound (trial_count alone, default buffer) is
     * unaffected by SI-13.1's new false_start_buffer bound. */
    public function testValidateDescriptionStillRejectsOversizedTrialCountAfterFalseStartBufferChange(): void
    {
        try {
            ScheduleCompiler::validateDescription([
                'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT + 1,
                'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
                'response_channels' => ['primary'],
                'timeout_ms' => null,
            ]);
            self::fail('Expected ApiException for oversized trial_count');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status());
            self::assertSame(['trial_count'], $e->details()['fields'] ?? null);
        }
    }

    // --- CR-TEST-28 (SI-13.2): resolved closing_speed_px_per_ms for Circle Collision ---

    /** Simple: closing_speed_px_per_ms = travel_distance_px / motion_duration_ms (per-trial,
     * constant for the whole trial since Simple has no mid-trial speed change). Default
     * travel_distance_px applies when the description omits it. */
    public function testSimpleResolvesClosingSpeedFromDefaultTravelDistance(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => 8000,
            'motion_duration_ms' => ['min' => 1000, 'max' => 1000], // fixed, deterministic
            'circle_radius_px' => 40,
        ]);

        $expected = ScheduleCompiler::DEFAULT_TRAVEL_DISTANCE_PX / 1000;
        foreach ($schedule['trials'] as $trial) {
            self::assertSame(1000, $trial['motion_duration_ms']);
            self::assertEqualsWithDelta($expected, $trial['closing_speed_px_per_ms'], 1e-9);
        }
    }

    /** Simple: an explicit travel_distance_px in the description overrides the default and is
     * reflected in the resolved closing speed. */
    public function testSimpleResolvesClosingSpeedFromExplicitTravelDistance(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => 3,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => 8000,
            'motion_duration_ms' => ['min' => 2000, 'max' => 2000],
            'circle_radius_px' => 40,
            'travel_distance_px' => 1200,
        ]);

        foreach ($schedule['trials'] as $trial) {
            self::assertEqualsWithDelta(1200 / 2000, $trial['closing_speed_px_per_ms'], 1e-9);
        }
    }

    /** Simple: an oversized/invalid travel_distance_px is rejected at import-validation time, same
     * pattern as every other new-field bound in this class. */
    public function testValidateDescriptionRejectsInvalidTravelDistance(): void
    {
        foreach ([0, -5, 'not-an-int'] as $bad) {
            try {
                ScheduleCompiler::validateDescription([
                    'trial_count' => 5,
                    'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
                    'response_channels' => ['primary'],
                    'timeout_ms' => null,
                    'motion_duration_ms' => ['min' => 1000, 'max' => 2000],
                    'travel_distance_px' => $bad,
                ]);
                self::fail('Expected ApiException for invalid travel_distance_px: ' . var_export($bad, true));
            } catch (ApiException $e) {
                self::assertSame(400, $e->status());
                self::assertSame(['travel_distance_px'], $e->details()['fields'] ?? null);
            }
        }
    }

    /** Complex: closing_speed_px_per_ms is the trial's average effective rate — the profile's own
     * travel_distance_px divided by the resolved (verified in-[1000,5000]ms) duration_ms. */
    public function testComplexResolvesAverageEffectiveClosingSpeed(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => 8000,
            'motion_speed_profile' => [
                'start_speed_px_per_s' => ['min' => 300, 'max' => 300],
                'mid_speed_px_per_s' => ['min' => 300, 'max' => 300],
                'end_speed_px_per_s' => ['min' => 300, 'max' => 300],
                'travel_distance_px' => 800,
            ],
            'circle_radius_px' => 40,
        ]);

        foreach ($schedule['trials'] as $trial) {
            $duration = $trial['motion_speed_profile']['duration_ms'];
            self::assertEqualsWithDelta(800 / $duration, $trial['closing_speed_px_per_ms'], 1e-9);
        }
    }

    /** Neither variant's fields present (classic discrete-stimulus test): closing_speed_px_per_ms
     * is never fabricated when there's no motion to derive it from. */
    public function testClassicTestNeverGetsClosingSpeedField(): void
    {
        $schedule = ScheduleCompiler::compile([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ]);

        foreach ($schedule['trials'] as $trial) {
            self::assertArrayNotHasKey('closing_speed_px_per_ms', $trial);
        }
    }

    /** abs_value_aggregation (SI-13.4) is present and true exactly when closing_speed_px_per_ms
     * is (both Circle Collision variants), absent for a classic discrete-stimulus test. */
    public function testAbsValueAggregationFlagPresentOnlyForCircleCollision(): void
    {
        $classic = ScheduleCompiler::compile([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
        ]);
        self::assertArrayNotHasKey('abs_value_aggregation', $classic);

        $simple = ScheduleCompiler::compile([
            'trial_count' => 5,
            'inter_stimulus_delay_ms' => ['min' => 100, 'max' => 200],
            'response_channels' => ['primary'],
            'timeout_ms' => 8000,
            'motion_duration_ms' => ['min' => 1000, 'max' => 5000],
            'circle_radius_px' => 40,
        ]);
        self::assertTrue($simple['abs_value_aggregation']);
    }

    /**
     * SI-13.6: the real on-disk seed files compile without error and carry the new resolved
     * closing_speed_px_per_ms/abs_value_aggregation fields — catches drift between the seed
     * content and ScheduleCompiler that a synthetic in-test description wouldn't.
     */
    public function testCircleCollisionSimpleSeedFileCompilesWithClosingSpeed(): void
    {
        $path = __DIR__ . '/../../database/seeds/circle-collision-simple.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'circle-collision-simple.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertTrue($schedule['abs_value_aggregation']);
        foreach ($schedule['trials'] as $trial) {
            self::assertArrayHasKey('closing_speed_px_per_ms', $trial);
            self::assertGreaterThan(0, $trial['closing_speed_px_per_ms']);
        }
    }

    public function testCircleCollisionComplexSeedFileCompilesWithClosingSpeed(): void
    {
        $path = __DIR__ . '/../../database/seeds/circle-collision-complex.v1.json';
        $description = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($description, 'circle-collision-complex.v1.json must be valid JSON');

        $schedule = ScheduleCompiler::compile($description);

        self::assertTrue($schedule['abs_value_aggregation']);
        foreach ($schedule['trials'] as $trial) {
            self::assertArrayHasKey('closing_speed_px_per_ms', $trial);
            self::assertGreaterThan(0, $trial['closing_speed_px_per_ms']);
        }
    }
}
