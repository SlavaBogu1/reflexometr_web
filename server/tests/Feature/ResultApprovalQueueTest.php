<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-AUTH-02 (implements D19): admin approval queue gating the peer-comparison pool. */
final class ResultApprovalQueueTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":1,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';

    private function seedTest(string $adminToken): void
    {
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));
    }

    private function submitOne(string $userToken, float $reactionMs): int
    {
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(5000);
        $trials = [['index' => 0, 'stimulus_at' => $run['schedule']['trials'][0]['delay_ms'], 'responses' => ['primary' => $run['schedule']['trials'][0]['delay_ms'] + $reactionMs]]];
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));
        return $body['data']['result_id'];
    }

    public function testNewResultStartsPendingAndIsExcludedFromOthersComparisonPool(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice@test.local');
        ['token' => $bobToken] = $this->registerUser('bob@test.local');
        $this->seedTest($adminToken);

        $aliceResult = $this->submitOne($aliceToken, 150);
        // Bob submits second, still pending; his own comparison sees only himself since Alice
        // is not yet approved.
        $bobResult = $this->submitOne($bobToken, 350);

        [, $bobComparison] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(1, $bobComparison['data']['total_participants']);
        self::assertNull($bobComparison['data']['percentile']);

        // Alice's own value is visible to her regardless of her own pending status.
        [, $aliceComparison] = $this->dispatch($this->requestAs($aliceToken, 'GET', "/results/{$aliceResult}/comparison"));
        self::assertSame(150.0, $aliceComparison['data']['your_value_ms']);
        self::assertSame(1, $aliceComparison['data']['total_participants']);
    }

    public function testApprovingAResultMakesItVisibleToOthersComparisonPool(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice2@test.local');
        ['token' => $bobToken] = $this->registerUser('bob2@test.local');
        $this->seedTest($adminToken);

        $aliceResult = $this->submitOne($aliceToken, 150);
        $bobResult = $this->submitOne($bobToken, 350);

        [, $before] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(1, $before['data']['total_participants']);

        [$approveStatus, $approveBody] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$aliceResult}", [
            'approval_status' => 'approved',
        ]));
        self::assertSame(200, $approveStatus);
        self::assertSame('approved', $approveBody['data']['approval_status']);

        [, $after] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(2, $after['data']['total_participants']);
        self::assertEqualsWithDelta(0.0, $after['data']['percentile'], 0.01); // Bob (350ms) beats 0% — slower than approved Alice (150ms)
    }

    public function testRejectedResultIsNeverDeletedAndNeverEntersComparisonPool(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice3@test.local');
        ['token' => $bobToken] = $this->registerUser('bob3@test.local');
        $this->seedTest($adminToken);

        $aliceResult = $this->submitOne($aliceToken, 150);
        $bobResult = $this->submitOne($bobToken, 350);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$aliceResult}", [
            'approval_status' => 'rejected',
        ]));
        self::assertSame(200, $status);
        self::assertSame('rejected', $body['data']['approval_status']);

        // Alice can still see her own result in her own history — row not deleted.
        [, $history] = $this->dispatch($this->requestAs($aliceToken, 'GET', '/r-tests/simple-reaction/versions/1/history'));
        self::assertCount(1, $history['data']['entries']);

        // Bob's pool still excludes Alice's rejected result.
        [, $bobComparison] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(1, $bobComparison['data']['total_participants']);
    }

    public function testNonAdminGetsAdminRequiredOnBothEndpoints(): void
    {
        ['token' => $userToken] = $this->registerUser('plain@test.local');

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'GET', '/admin/results', []));
        self::assertSame(403, $status);
        self::assertSame('ADMIN_REQUIRED', $body['error']['code']);

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'PATCH', '/admin/results/1', ['approval_status' => 'approved']));
        self::assertSame(403, $status);
        self::assertSame('ADMIN_REQUIRED', $body['error']['code']);
    }

    public function testAdminCanListPendingResults(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('lister@test.local');
        $this->seedTest($adminToken);

        $r1 = $this->submitOne($userToken, 150);
        $r2 = $this->submitOne($userToken, 200);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/results', ['status' => 'pending']));
        self::assertSame(200, $status);
        self::assertSame(2, $body['data']['total']);
        $ids = array_column($body['data']['entries'], 'id');
        self::assertContains($r1, $ids);
        self::assertContains($r2, $ids);
        // Never exposes the submitting user's identity.
        self::assertStringNotContainsString('lister@test.local', json_encode($body));

        $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$r1}", ['approval_status' => 'approved']));

        [, $afterApprove] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/results', ['status' => 'pending']));
        self::assertSame(1, $afterApprove['data']['total']);
    }

    public function testUnknownResultIdReturnsNotFound(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/results/999999', ['approval_status' => 'approved']));
        self::assertSame(404, $status);
        self::assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function testInvalidApprovalStatusValueIsRejected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('badstatus@test.local');
        $this->seedTest($adminToken);
        $resultId = $this->submitOne($userToken, 150);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$resultId}", [
            'approval_status' => 'not-a-real-status',
        ]));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }

    public function testExistingPreMigrationResultsAreBackfilledApproved(): void
    {
        // Simulates a result that existed before CR-AUTH-02 shipped, inserted directly at the
        // repository layer bypassing the normal 'pending' default (mirrors how a real backfill
        // UPDATE would have already flipped it by the time this code runs against a live DB).
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken, 'user' => $user] = $this->registerUser('legacy@test.local');
        $this->seedTest($adminToken);

        $db = \Reflexometr\Database::connection();
        $rTest = (new \Reflexometr\Repositories\RTestRepository($db))->findBySlug('simple-reaction');
        $version = (new \Reflexometr\Repositories\RTestVersionRepository($db))->findByTestAndVersion((int) $rTest['id'], 1);
        $runTokens = new \Reflexometr\Repositories\RunTokenRepository($db);
        $results = new \Reflexometr\Repositories\ResultRepository($db);

        $tokenId = $runTokens->create(
            'legacy-token', (int) $user['id'], (int) $rTest['id'], (int) $version['id'], 'single', null,
            '{"trial_count":1,"buffer_trials":0,"response_channels":["primary"],"timeout_ms":null,"trials":[{"index":0,"delay_ms":100}]}',
            1000, 1000000,
        );
        $resultId = $results->create(
            (int) $user['id'], (int) $rTest['id'], (int) $version['id'], $tokenId, null, 1,
            '[{"index":0,"stimulus_at":100,"responses":{"primary":250}}]', '{"overall":{"mean_ms":150}}',
            150.0, null, null, 1000, 1000000,
        );
        // Simulate the one-time backfill (schema.mysql.sql's UPDATE ... approved for pre-existing rows).
        $results->updateApprovalStatus($resultId, 'approved');

        ['token' => $bobToken] = $this->registerUser('bob-legacy@test.local');
        $bobResult = $this->submitOne($bobToken, 350);

        [, $bobComparison] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertSame(2, $bobComparison['data']['total_participants']);
    }
}
