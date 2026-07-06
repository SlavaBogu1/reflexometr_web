<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Services\ScheduleCompiler;
use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/**
 * CR-TEST-02 + D9 (injection prevention) + D11 (compiled schedule, never raw description).
 */
final class RunTokenFlowTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":5,"inter_stimulus_delay_ms":{"min":1000,"max":2000},"response_channels":["primary"],"timeout_ms":null}';

    private function seedTestAndStartRun(string $userToken): array
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        self::assertSame(201, $status);
        return $body['data'];
    }

    /**
     * Build a structurally-valid trial log — the happy path with zero false starts, so it only
     * ever consumes the first `trial_count` resolved delays, never the false-start buffer.
     */
    private function validTrials(array $schedule, int $reactionMs = 250): array
    {
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = [
                'index' => $i,
                'stimulus_at' => $cursor,
                'responses' => ['primary' => $cursor + $reactionMs],
            ];
        }
        return $trials;
    }

    public function testStartRunNeverExposesRawDescriptionRanges(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);

        self::assertArrayHasKey('schedule', $run);
        self::assertArrayNotHasKey('inter_stimulus_delay_ms', $run['schedule']);
        self::assertSame(5, $run['schedule']['trial_count']);
        self::assertCount(5 + ScheduleCompiler::DEFAULT_FALSE_START_BUFFER, $run['schedule']['trials']);
        foreach ($run['schedule']['trials'] as $trial) {
            self::assertGreaterThanOrEqual(1000, $trial['delay_ms']);
            self::assertLessThanOrEqual(2000, $trial['delay_ms']);
        }
    }

    public function testSubmitSucceedsOnce(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);

        Clock::advance(20_000); // plenty of elapsed wall-clock time for 5 trials

        $trials = $this->validTrials($run['schedule']);
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertArrayHasKey('result_id', $body['data']);
        self::assertIsFloat($body['data']['primary_metric_ms']);
        self::assertEqualsWithDelta(250.0, $body['data']['primary_metric_ms'], 0.01);
    }

    public function testTokenCannotBeReplayed(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        Clock::advance(20_000);
        $trials = $this->validTrials($run['schedule']);

        $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(409, $status);
        self::assertSame('RUN_TOKEN_ALREADY_USED', $body['error']['code']);
    }

    public function testExpiredTokenRejected(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        $trials = $this->validTrials($run['schedule']);

        Clock::advance((int) $run['expires_at_ms'] - Clock::nowMs() + 1000); // past expiry

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(410, $status);
        self::assertSame('RUN_TOKEN_EXPIRED', $body['error']['code']);
    }

    public function testNonMonotonicTimestampsRejected(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        Clock::advance(20_000);

        $trials = $this->validTrials($run['schedule']);
        $trials[1]['stimulus_at'] = $trials[0]['stimulus_at']; // stimulus 1 fired impossibly early
        $trials[1]['responses']['primary'] = $trials[1]['stimulus_at'] + 250;

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(400, $status);
        self::assertSame('TRIAL_LOG_INVALID', $body['error']['code']);
        self::assertSame('NON_MONOTONIC_TIMESTAMPS', $body['error']['details']['reason']);
    }

    public function testReactionBeforeStimulusRejected(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        Clock::advance(20_000);

        $trials = $this->validTrials($run['schedule']);
        $trials[0]['responses']['primary'] = $trials[0]['stimulus_at'] - 10; // "reacted" before the stimulus

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(400, $status);
        self::assertSame('REACTION_BEFORE_STIMULUS', $body['error']['details']['reason']);
    }

    public function testTrialCountMismatchRejected(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        Clock::advance(20_000);

        $trials = $this->validTrials($run['schedule']);
        array_pop($trials); // submit only 4 of the 5 scheduled trials

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(400, $status);
        self::assertSame('TRIAL_COUNT_MISMATCH', $body['error']['details']['reason']);
    }

    public function testInstantaneousBulkSubmissionRejectedByWallClockCheck(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $run = $this->seedTestAndStartRun($userToken);
        // No Clock::advance() — submitting "immediately" after issuance, implausible for 5 trials
        // each with >=1000ms scheduled delay.

        $trials = $this->validTrials($run['schedule']);
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(400, $status);
        self::assertSame('WALLCLOCK_TOO_FAST', $body['error']['details']['reason']);
    }

    public function testTokenCannotBeUsedByAnotherUser(): void
    {
        ['token' => $ownerToken] = $this->registerUser('owner@test.local');
        $run = $this->seedTestAndStartRun($ownerToken);
        ['token' => $attackerToken] = $this->registerUser('attacker@test.local');

        Clock::advance(20_000);
        $trials = $this->validTrials($run['schedule']);

        [$status, $body] = $this->dispatch($this->requestAs($attackerToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(400, $status);
        self::assertSame('RUN_TOKEN_INVALID', $body['error']['code']);
    }

    public function testUnknownTokenRejected(): void
    {
        ['token' => $userToken] = $this->registerUser();
        $this->seedTestAndStartRun($userToken);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/runs/does-not-exist/submit', [
            'trials' => [],
        ]));
        self::assertSame(400, $status);
        self::assertSame('RUN_TOKEN_INVALID', $body['error']['code']);
    }
}
