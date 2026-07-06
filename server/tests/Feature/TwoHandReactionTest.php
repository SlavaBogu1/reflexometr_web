<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-TEST-04: two-hand-reaction seed r-test + dominant_hand captured at submission time. */
final class TwoHandReactionTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":3,"inter_stimulus_delay_ms":{"min":500,"max":500},"response_channels":["left","right"],"timeout_ms":2000}';

    private function seedTest(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'two-hand-reaction', 'name' => 'Two-Hand Reaction', 'content' => self::CONTENT_V1,
        ]));
    }

    /** @param callable(float):array<string,mixed> $responsesFor */
    private function buildTrials(array $schedule, callable $responsesFor): array
    {
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => $responsesFor($cursor)];
        }
        return $trials;
    }

    public function testResultCapturesBothHandsAndSignedDelta(): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();
        $this->dispatch($this->requestAs($userToken, 'PATCH', '/profile', ['dominant_hand' => 'right']));

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/two-hand-reaction/runs', []));
        $run = $run['data'];
        self::assertSame(['left', 'right'], $run['schedule']['response_channels']);

        Clock::advance(10_000);
        // right (dominant) faster than left on every trial.
        $trials = $this->buildTrials($run['schedule'], static fn (float $c) => ['left' => $c + 300, 'right' => $c + 200]);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", [
            'trials' => $trials,
            'dominant_hand' => 'right', // captured at submission time, per CR-TEST-04
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(-100.0, $body['data']['summary']['dominant_minus_nondominant_ms']);
    }

    public function testChangingProfileDominantHandLaterDoesNotAlterPastResult(): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/two-hand-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(10_000);

        $trials = $this->buildTrials($run['schedule'], static fn (float $c) => ['left' => $c + 200, 'right' => $c + 300]);

        [, $submitBody] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", [
            'trials' => $trials,
            'dominant_hand' => 'left',
        ]));
        $resultId = $submitBody['data']['result_id'];

        // Now the user changes their profile to 'right' — the already-stored result must be unaffected.
        $this->dispatch($this->requestAs($userToken, 'PATCH', '/profile', ['dominant_hand' => 'right']));

        [, $history] = $this->dispatch($this->requestAs($userToken, 'GET', '/r-tests/two-hand-reaction/versions/1/history'));
        $entry = $history['data']['entries'][0];
        self::assertSame($resultId, $entry['result_id']);
        // left (dominant at submission time) was faster (200 < 300) -> negative delta preserved.
        self::assertSame(-100.0, $entry['summary']['dominant_minus_nondominant_ms']);
    }

    public function testTimeoutOnOneHandIsAcceptedGivenConfiguredTimeout(): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/two-hand-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(10_000);

        $trials = $this->buildTrials($run['schedule'], static fn (float $c) => ['left' => $c + 250, 'right' => null]);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(3, $body['data']['summary']['channels']['right']['timeouts']);
    }
}
