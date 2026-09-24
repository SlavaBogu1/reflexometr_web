<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-STATS-07: exclude-from-own-stats toggle (ServerTeam half). */
final class ExcludeFromOwnStatsTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":2,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';

    /** @return array<int,array<string,mixed>> */
    private function trialsWithReaction(array $schedule, float $reactionMs): array
    {
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => ['primary' => $cursor + $reactionMs]];
        }
        return $trials;
    }

    private function runAndSubmit(string $userToken, float $reactionMs): int
    {
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs'));
        $run = $run['data'];
        Clock::advance(5000);
        $trials = $this->trialsWithReaction($run['schedule'], $reactionMs);
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));
        return $body['data']['result_id'];
    }

    private function seedRTest(string $adminToken): void
    {
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));
    }

    public function testUserCanToggleOwnResultExclusion(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('alice@test.local');
        $this->seedRTest($adminToken);

        $resultId = $this->runAndSubmit($userToken, 200);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'PATCH', "/results/{$resultId}/exclude", ['excluded' => true]));
        self::assertSame(200, $status, (string) json_encode($body));
        self::assertSame(['result_id' => $resultId, 'excluded' => true], $body['data']);

        // Toggle back off.
        [$status2, $body2] = $this->dispatch($this->requestAs($userToken, 'PATCH', "/results/{$resultId}/exclude", ['excluded' => false]));
        self::assertSame(200, $status2);
        self::assertSame(['result_id' => $resultId, 'excluded' => false], $body2['data']);
    }

    public function testExcludingAnotherUsersResultReturnsNotFound(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice2@test.local');
        ['token' => $bobToken] = $this->registerUser('bob2@test.local');
        $this->seedRTest($adminToken);

        $aliceResult = $this->runAndSubmit($aliceToken, 150);

        [$status, $body] = $this->dispatch($this->requestAs($bobToken, 'PATCH', "/results/{$aliceResult}/exclude", ['excluded' => true]));
        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testExcludingUnknownResultReturnsNotFoundSameCode(): void
    {
        ['token' => $userToken] = $this->registerUser('carol@test.local');

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'PATCH', '/results/999999/exclude', ['excluded' => true]));
        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testExcludeRejectsMissingOrNonBooleanField(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('dave@test.local');
        $this->seedRTest($adminToken);
        $resultId = $this->runAndSubmit($userToken, 150);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'PATCH', "/results/{$resultId}/exclude", []));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        [$status2, $body2] = $this->dispatch($this->requestAs($userToken, 'PATCH', "/results/{$resultId}/exclude", ['excluded' => 'yes']));
        self::assertSame(400, $status2);
        self::assertSame('VALIDATION_ERROR', $body2['error']['code']);
    }

    public function testHistoryCarriesExcludedFieldWithoutFilteringItOut(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('erin@test.local');
        $this->seedRTest($adminToken);

        $r1 = $this->runAndSubmit($userToken, 150);
        $r2 = $this->runAndSubmit($userToken, 250);

        $this->dispatch($this->requestAs($userToken, 'PATCH', "/results/{$r1}/exclude", ['excluded' => true]));

        [$status, $history] = $this->dispatch($this->requestAs($userToken, 'GET', '/r-tests/simple-reaction/versions/1/history'));
        self::assertSame(200, $status);
        self::assertCount(2, $history['data']['entries']); // both still present, never filtered server-side

        $byId = [];
        foreach ($history['data']['entries'] as $entry) {
            $byId[$entry['result_id']] = $entry;
        }
        self::assertTrue($byId[$r1]['excluded']);
        self::assertFalse($byId[$r2]['excluded']);
    }

    public function testComparisonUnaffectedByOwnExclusionFlag(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('frank@test.local');
        ['token' => $bobToken] = $this->registerUser('grace@test.local');
        $this->seedRTest($adminToken);

        $aliceResult = $this->runAndSubmit($aliceToken, 150);
        $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$aliceResult}", ['approval_status' => 'approved']));
        $bobResult = $this->runAndSubmit($bobToken, 350);
        $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$bobResult}", ['approval_status' => 'approved']));

        [, $beforeExclude] = $this->dispatch($this->requestAs($aliceToken, 'GET', "/results/{$aliceResult}/comparison"));

        // Alice excludes her own result from her own stats view.
        [$excludeStatus] = $this->dispatch($this->requestAs($aliceToken, 'PATCH', "/results/{$aliceResult}/exclude", ['excluded' => true]));
        self::assertSame(200, $excludeStatus);

        [, $afterExclude] = $this->dispatch($this->requestAs($aliceToken, 'GET', "/results/{$aliceResult}/comparison"));

        // The peer-comparison pool (D19) is completely untouched by the exclusion toggle.
        self::assertSame($beforeExclude['data'], $afterExclude['data']);
    }
}
