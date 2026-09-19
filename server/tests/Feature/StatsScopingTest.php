<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-STATS-01 (version-scoped, never mixed) + CR-STATS-02 (anonymized comparison, D12). */
final class StatsScopingTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":2,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';
    private const CONTENT_V2 = '{"trial_count":2,"inter_stimulus_delay_ms":{"min":200,"max":200},"response_channels":["primary"],"timeout_ms":null}';

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

    private function runAndSubmit(string $userToken, ?int $version, float $reactionMs): int
    {
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', $version !== null ? ['version' => $version] : []));
        $run = $run['data'];
        Clock::advance(5000);
        $trials = $this->trialsWithReaction($run['schedule'], $reactionMs);
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));
        return $body['data']['result_id'];
    }

    /**
     * CR-AUTH-02/D19: a newly-submitted result starts `pending` and does not affect the peer
     * comparison pool until an admin approves it — these pre-existing scoping tests care about
     * pool composition, not the approval workflow itself, so approve immediately after submit to
     * preserve their original intent (pool visibility as soon as a result is legitimately usable).
     */
    private function approve(string $adminToken, int $resultId): void
    {
        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$resultId}", [
            'approval_status' => 'approved',
        ]));
        self::assertSame(200, $status, (string) json_encode($body));
    }

    public function testHistoryAndComparisonNeverMixVersions(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('alice@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));
        [, $showV1] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/simple-reaction'));
        self::assertSame(1, $showV1['data']['current_version']);

        $r1 = $this->runAndSubmit($userToken, null, 200);
        $this->approve($adminToken, $r1);
        $r2 = $this->runAndSubmit($userToken, 1, 220);
        $this->approve($adminToken, $r2);

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/simple-reaction/versions', ['content' => self::CONTENT_V2]));
        [, $showV2] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/simple-reaction'));
        self::assertSame(2, $showV2['data']['current_version']);

        $r3 = $this->runAndSubmit($userToken, null, 300); // lands on v2 (now active)
        $this->approve($adminToken, $r3);

        [$status, $historyV1] = $this->dispatch($this->requestAs($userToken, 'GET', '/r-tests/simple-reaction/versions/1/history'));
        self::assertSame(200, $status);
        self::assertCount(2, $historyV1['data']['entries']);

        [, $historyV2] = $this->dispatch($this->requestAs($userToken, 'GET', '/r-tests/simple-reaction/versions/2/history'));
        self::assertCount(1, $historyV2['data']['entries']);

        // r_test_version_id differs between the two history responses — never merged.
        self::assertNotSame($historyV1['data']['r_test_version_id'], $historyV2['data']['r_test_version_id']);

        // Comparison for the v2 result is scoped to v2 only: only 1 participant so far.
        [, $comparisonR3] = $this->dispatch($this->requestAs($userToken, 'GET', "/results/{$r3}/comparison"));
        self::assertSame(1, $comparisonR3['data']['total_participants']);
        self::assertSame($historyV2['data']['r_test_version_id'], $comparisonR3['data']['r_test_version_id']);

        // Comparison for a v1 result counts only the 2 v1 participants, not v2's.
        [, $comparisonR1] = $this->dispatch($this->requestAs($userToken, 'GET', "/results/{$r1}/comparison"));
        self::assertSame(2, $comparisonR1['data']['total_participants']);

        unset($r2); // used only to build up v1 history count
    }

    public function testComparisonNeverExposesOtherUsersIdentityOrRawList(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice2@test.local');
        ['token' => $bobToken] = $this->registerUser('bob2@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        $aliceResult = $this->runAndSubmit($aliceToken, null, 150); // faster
        $this->approve($adminToken, $aliceResult);
        $bobResult = $this->runAndSubmit($bobToken, null, 350); // slower
        $this->approve($adminToken, $bobResult);

        [, $aliceComparison] = $this->dispatch($this->requestAs($aliceToken, 'GET', "/results/{$aliceResult}/comparison"));
        $data = $aliceComparison['data'];

        self::assertSame(
            ['r_test_id', 'r_test_version_id', 'your_value_ms', 'your_sd_ms', 'your_cv', 'percentile', 'rank', 'total_participants', 'peer_sd_ms_median'],
            array_keys($data)
        );
        self::assertSame(2, $data['total_participants']);
        self::assertSame(1, $data['rank']); // Alice is faster -> rank 1
        self::assertEqualsWithDelta(100.0, $data['percentile'], 0.01); // faster than 100% of the field (Bob only)

        [, $bobComparison] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(2, $bobComparison['data']['rank']);
        self::assertEqualsWithDelta(0.0, $bobComparison['data']['percentile'], 0.01);
    }

    public function testUserCannotViewAnotherUsersResultComparison(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice3@test.local');
        ['token' => $bobToken] = $this->registerUser('bob3@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));
        $aliceResult = $this->runAndSubmit($aliceToken, null, 150);

        [$status, $body] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$aliceResult}/comparison"));
        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }
}
