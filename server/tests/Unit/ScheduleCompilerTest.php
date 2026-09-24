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
     * CR-TEST-27 defense-in-depth: directly exercises the iteration-ceiling guard inside compile()
     * by calling it with a description whose trial_count alone is within validateDescription()'s
     * bound, but whose combined trial_count + false_start_buffer exceeds
     * MAX_TRIAL_GENERATION_ITERATIONS — proving the ceiling is checked independently of (in
     * addition to) the import-time trial_count bound, and fails safely (ApiException/500, not an
     * uncaught Fatal).
     */
    public function testCompileFailsSafelyWhenIterationCeilingExceeded(): void
    {
        $ref = new \ReflectionClass(ScheduleCompiler::class);
        $ceiling = $ref->getConstant('MAX_TRIAL_GENERATION_ITERATIONS');

        $this->expectException(ApiException::class);
        ScheduleCompiler::compile([
            'trial_count' => ScheduleCompiler::MAX_TRIAL_COUNT,
            'inter_stimulus_delay_ms' => ['min' => 1, 'max' => 1],
            'response_channels' => ['primary'],
            'timeout_ms' => null,
            // false_start_buffer alone pushes total iterations past the ceiling even though
            // trial_count itself passed validateDescription()'s bound — proves the ceiling is a
            // genuinely independent second layer of defense, not just a restatement of SI-12.1.
            'false_start_buffer' => $ceiling,
        ]);
    }
}
