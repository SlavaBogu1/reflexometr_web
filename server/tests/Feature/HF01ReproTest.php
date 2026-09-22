<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/**
 * HF-01 (CR-TEST-21) reproduction harness: exercises the FULL submitRun() path (RunService ->
 * ResultSummaryService::compute() -> json_encode -> ResultRepository::create()), not just
 * compute() in isolation like the earlier sandbox test, against every scenario reported live:
 * final-trial timeout (both hands, one hand), mid-run timeout, and a 10-trial run matching the
 * user's original screenshot ("Trial 10 of 10"). Temporary — not part of the permanent regression
 * suite scope decision (left in place for the Tester's re-validation pass; ProductOwner/Tester may
 * fold the useful cases into TwoHandReactionTest.php permanently or remove this file).
 */
final class HF01ReproTest extends TestCase
{
    private const CONTENT_10 = '{"trial_count":10,"inter_stimulus_delay_ms":{"min":500,"max":500},"response_channels":["left","right"],"timeout_ms":2000}';

    private function seedTest(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'two-hand-reaction', 'name' => 'Two-Hand Reaction', 'content' => self::CONTENT_10,
        ]));
    }

    /** @param callable(int,float):array<string,mixed> $responsesFor */
    private function buildTrials(array $schedule, callable $responsesFor): array
    {
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => $responsesFor($i, $cursor)];
        }
        return $trials;
    }

    private function runAndSubmit(callable $responsesFor, ?string $dominantHand = null): array
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/two-hand-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(60_000);
        $trials = $this->buildTrials($run['schedule'], $responsesFor);
        $body = ['trials' => $trials];
        if ($dominantHand !== null) {
            $body['dominant_hand'] = $dominantHand;
        }
        return $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", $body));
    }

    public function testFinalTrialBothHandsTimeout(): void
    {
        [$status, $body] = $this->runAndSubmit(
            static fn (int $i, float $c) => $i === 9
                ? ['left' => null, 'right' => null]
                : ['left' => $c + 250, 'right' => $c + 220],
            'right',
        );
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(1, $body['data']['summary']['channels']['left']['timeouts']);
        self::assertSame(1, $body['data']['summary']['channels']['right']['timeouts']);
    }

    public function testFinalTrialOneHandTimeout(): void
    {
        [$status, $body] = $this->runAndSubmit(
            static fn (int $i, float $c) => $i === 9
                ? ['left' => $c + 300, 'right' => null]
                : ['left' => $c + 250, 'right' => $c + 220],
            'right',
        );
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(0, $body['data']['summary']['channels']['left']['timeouts']);
        self::assertSame(1, $body['data']['summary']['channels']['right']['timeouts']);
    }

    public function testMidRunTimeoutAtIndex3(): void
    {
        [$status, $body] = $this->runAndSubmit(
            static fn (int $i, float $c) => $i === 3
                ? ['left' => null, 'right' => $c + 210]
                : ['left' => $c + 260, 'right' => $c + 230],
        );
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(1, $body['data']['summary']['channels']['left']['timeouts']);
    }

    public function testEveryTrialBothHandsTimeoutStillSubmits(): void
    {
        // Degenerate case: zero valid responses across the whole run (overallMeanMs = null).
        [$status, $body] = $this->runAndSubmit(
            static fn (int $i, float $c) => ['left' => null, 'right' => null],
        );
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertNull($body['data']['summary']['overall']['mean_ms']);
        self::assertSame(0.0, $body['data']['primary_metric_ms']);
    }

    public function testDominantHandChannelFullyTimedOutOmitsDelta(): void
    {
        // dominant_hand = 'right' but every 'right' response timed out -> meanB null -> the
        // dominant_minus_nondominant_ms key must be omitted, not NAN.
        [$status, $body] = $this->runAndSubmit(
            static fn (int $i, float $c) => ['left' => $c + 240, 'right' => null],
            'right',
        );
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertArrayNotHasKey('dominant_minus_nondominant_ms', $body['data']['summary']);
    }
}
