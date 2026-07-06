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
}
