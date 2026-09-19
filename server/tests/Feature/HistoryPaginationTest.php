<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Database;
use Reflexometr\Http\Request;
use Reflexometr\Repositories\ResultRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Repositories\RunTokenRepository;
use Reflexometr\Tests\TestCase;

/**
 * CR-STATS-04: the history endpoint used to hardcap results at 50 with no caller-facing way to
 * request more, and no signal in the response that truncation had happened. These tests verify
 * the fix: complete-history-by-default for a user with 100+ results, no regression for empty/
 * small histories, and that the new optional limit/offset paging + validation behave correctly.
 */
final class HistoryPaginationTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":1,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';

    /**
     * Seeds $count results directly via the repositories (bypassing the full run/submit HTTP
     * flow, which would be needlessly slow for 100+ rows) for one user/r-test/version scope.
     */
    private function seedResults(int $userId, int $rTestId, int $rTestVersionId, int $count): void
    {
        $db = Database::connection();
        $runTokens = new RunTokenRepository($db);
        $results = new ResultRepository($db);

        for ($i = 0; $i < $count; $i++) {
            $tokenId = $runTokens->create(
                'seed-token-' . $userId . '-' . $rTestVersionId . '-' . $i,
                $userId,
                $rTestId,
                $rTestVersionId,
                'single',
                null,
                '{"trial_count":1,"buffer_trials":0,"response_channels":["primary"],"timeout_ms":null,"trials":[{"index":0,"delay_ms":100}]}',
                1000 + $i,
                1000000 + $i,
            );
            $results->create(
                $userId,
                $rTestId,
                $rTestVersionId,
                $tokenId,
                null,
                1,
                '[{"index":0,"stimulus_at":100,"responses":{"primary":250}}]',
                '{"overall":{"mean_ms":250}}',
                200.0 + $i,
                null,
                null,
                1000 + $i,
                1000000 + $i,
            );
        }
    }

    /** @param array<string,string> $query */
    private function requestWithQuery(string $token, string $path, array $query): Request
    {
        return Request::fromArrays('GET', $path, ['Authorization' => 'Bearer ' . $token], $query);
    }

    public function testUserWith100PlusResultsRetrievesCompleteHistoryByDefault(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken, 'user' => $user] = $this->registerUser('heavy@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        $db = Database::connection();
        $rTest = (new RTestRepository($db))->findBySlug('simple-reaction');
        $version = (new RTestVersionRepository($db))->findByTestAndVersion((int) $rTest['id'], 1);

        $this->seedResults((int) $user['id'], (int) $rTest['id'], (int) $version['id'], 125);

        [$status, $body] = $this->dispatch(
            $this->requestWithQuery($userToken, '/r-tests/simple-reaction/versions/1/history', [])
        );

        self::assertSame(200, $status);
        self::assertCount(125, $body['data']['entries']);
        self::assertSame(125, $body['data']['total']);
    }

    public function testSmallHistoryUnderFiftyIsNotAffected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken, 'user' => $user] = $this->registerUser('light@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        $db = Database::connection();
        $rTest = (new RTestRepository($db))->findBySlug('simple-reaction');
        $version = (new RTestVersionRepository($db))->findByTestAndVersion((int) $rTest['id'], 1);

        $this->seedResults((int) $user['id'], (int) $rTest['id'], (int) $version['id'], 7);

        [$status, $body] = $this->dispatch(
            $this->requestWithQuery($userToken, '/r-tests/simple-reaction/versions/1/history', [])
        );

        self::assertSame(200, $status);
        self::assertCount(7, $body['data']['entries']);
        self::assertSame(7, $body['data']['total']);
    }

    public function testEmptyHistoryStillReturnsEmptyArrayNotAnError(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('none@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch(
            $this->requestWithQuery($userToken, '/r-tests/simple-reaction/versions/1/history', [])
        );

        self::assertSame(200, $status);
        self::assertSame([], $body['data']['entries']);
        self::assertSame(0, $body['data']['total']);
    }

    public function testExplicitLimitAndOffsetPageThroughFullHistory(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken, 'user' => $user] = $this->registerUser('paged@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        $db = Database::connection();
        $rTest = (new RTestRepository($db))->findBySlug('simple-reaction');
        $version = (new RTestVersionRepository($db))->findByTestAndVersion((int) $rTest['id'], 1);

        $this->seedResults((int) $user['id'], (int) $rTest['id'], (int) $version['id'], 125);

        [$status1, $page1] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['limit' => '50', 'offset' => '0']
        ));
        [$status2, $page2] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['limit' => '50', 'offset' => '50']
        ));
        [$status3, $page3] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['limit' => '50', 'offset' => '100']
        ));

        self::assertSame(200, $status1);
        self::assertSame(200, $status2);
        self::assertSame(200, $status3);
        self::assertCount(50, $page1['data']['entries']);
        self::assertCount(50, $page2['data']['entries']);
        self::assertCount(25, $page3['data']['entries']);
        self::assertSame(125, $page1['data']['total']);
        self::assertSame(125, $page2['data']['total']);
        self::assertSame(125, $page3['data']['total']);

        // Pages don't overlap and together reconstruct the full set of result ids.
        $ids1 = array_column($page1['data']['entries'], 'result_id');
        $ids2 = array_column($page2['data']['entries'], 'result_id');
        $ids3 = array_column($page3['data']['entries'], 'result_id');
        self::assertCount(0, array_intersect($ids1, $ids2));
        self::assertCount(0, array_intersect($ids2, $ids3));
        self::assertCount(125, array_unique(array_merge($ids1, $ids2, $ids3)));
    }

    public function testInvalidLimitOrOffsetIsRejectedWithValidationError(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('badquery@test.local');

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['limit' => '0']
        ));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        [$status, $body] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['limit' => 'abc']
        ));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);

        [$status, $body] = $this->dispatch($this->requestWithQuery(
            $userToken, '/r-tests/simple-reaction/versions/1/history', ['offset' => '-1']
        ));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }
}
