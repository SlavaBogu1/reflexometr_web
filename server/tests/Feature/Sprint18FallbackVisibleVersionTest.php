<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

/**
 * Sprint 18 (CR-UI-31): a multi-version r-test whose active version gets archived
 * (is_visible = 0) must not disappear from the public surface if an older version of the
 * same test is still visible — RTestController should fall back to the highest-numbered
 * visible version via RTestVersionRepository::findEffectiveVersionForTest().
 *
 * Covers SI-18.4's three scenarios:
 *  1. multi-version test, active version archived, older version visible -> still appears,
 *     showing the older version's data (both GET /r-tests and GET /r-tests/{slug}).
 *  2. multi-version test, ALL versions archived -> still excluded from both endpoints.
 *  3. single-version test, archived -> unchanged (still excluded) regression check.
 * Plus a baseline regression check that the normal case (active version visible) is untouched.
 */
final class Sprint18FallbackVisibleVersionTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":10,"inter_stimulus_delay_ms":{"min":1000,"max":3000},"response_channels":["primary"],"timeout_ms":null}';
    private const CONTENT_V2 = '{"trial_count":12,"inter_stimulus_delay_ms":{"min":1000,"max":3000},"response_channels":["primary"],"timeout_ms":null}';

    // -------------------------------------------------------------------------
    // Scenario 1: active version archived, older version still visible -> fallback
    // -------------------------------------------------------------------------

    public function testPublicListFallsBackToOlderVisibleVersionWhenActiveIsArchived(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'temporal-prediction',
            'name' => 'Temporal Prediction',
            'content' => self::CONTENT_V1,
        ]));
        // Import v2 — becomes the active version (createAsActive flips v1's is_active off).
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/temporal-prediction/versions', [
            'content' => self::CONTENT_V2,
        ]));

        // Archive v2 (the active one). v1 remains visible.
        [$patchStatus, $patchBody] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/temporal-prediction/versions/2', [
            'is_visible' => false,
        ]));
        self::assertSame(200, $patchStatus, (string) json_encode($patchBody));

        // GET /r-tests — the test must still appear, showing v1 (the fallback), not v2.
        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $entry = null;
        foreach ($listBody['data'] as $row) {
            if ($row['slug'] === 'temporal-prediction') {
                $entry = $row;
                break;
            }
        }
        self::assertNotNull($entry, 'Test with an archived active version but a visible older version must still appear in /r-tests');
        self::assertSame(1, $entry['current_version'], 'Should fall back to the highest visible version (v1), not v2 (archived)');

        // GET /r-tests/{slug} — same fallback behavior.
        [$showStatus, $showBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/temporal-prediction'));
        self::assertSame(200, $showStatus, (string) json_encode($showBody));
        self::assertSame(1, $showBody['data']['current_version']);
    }

    // -------------------------------------------------------------------------
    // Scenario 2: ALL versions archived -> still excluded
    // -------------------------------------------------------------------------

    public function testPublicSurfaceExcludesTestWhenAllVersionsArchived(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'all-archived-test',
            'name' => 'All Archived Test',
            'content' => self::CONTENT_V1,
        ]));
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/all-archived-test/versions', [
            'content' => self::CONTENT_V2,
        ]));

        // Archive both versions.
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/all-archived-test/versions/1', [
            'is_visible' => false,
        ]));
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/all-archived-test/versions/2', [
            'is_visible' => false,
        ]));

        // GET /r-tests — must be excluded (zero visible versions anywhere, no fallback possible).
        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $slugs = array_column($listBody['data'], 'slug');
        self::assertNotContains('all-archived-test', $slugs, 'A test with zero visible versions must stay excluded even with the fallback in place');

        // GET /r-tests/{slug} — existing behavior preserved: test metadata still returns,
        // but with current_version/current_version_id null (no version data to show).
        [$showStatus, $showBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/all-archived-test'));
        self::assertSame(200, $showStatus);
        self::assertNull($showBody['data']['current_version']);
        self::assertNull($showBody['data']['current_version_id']);
    }

    // -------------------------------------------------------------------------
    // Scenario 3: single-version test archived -> unchanged (regression check)
    // -------------------------------------------------------------------------

    public function testSingleVersionTestArchiveBehaviorUnchanged(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'single-version-test',
            'name' => 'Single Version Test',
            'content' => self::CONTENT_V1,
        ]));

        // Archive the only version — nothing to fall back to.
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/single-version-test/versions/1', [
            'is_visible' => false,
        ]));

        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $slugs = array_column($listBody['data'], 'slug');
        self::assertNotContains('single-version-test', $slugs, 'Single-version test with its only version archived must stay excluded');
    }

    // -------------------------------------------------------------------------
    // Regression: normal case (active version visible) is untouched by the fallback addition
    // -------------------------------------------------------------------------

    public function testNormalCaseActiveVisibleVersionStillUsedDirectly(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'normal-visible-test',
            'name' => 'Normal Visible Test',
            'content' => self::CONTENT_V1,
        ]));
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/normal-visible-test/versions', [
            'content' => self::CONTENT_V2,
        ]));
        // No archiving at all — v2 is active and visible.

        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $entry = null;
        foreach ($listBody['data'] as $row) {
            if ($row['slug'] === 'normal-visible-test') {
                $entry = $row;
                break;
            }
        }
        self::assertNotNull($entry);
        self::assertSame(2, $entry['current_version'], 'Normal case: active+visible version (v2) used directly, fallback not engaged');

        [, $showBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/normal-visible-test'));
        self::assertSame(2, $showBody['data']['current_version']);
    }

    // -------------------------------------------------------------------------
    // SI-18.3: RunService::startRun() consistency — same fallback, no explicit version requested
    // -------------------------------------------------------------------------

    public function testStartRunFallsBackToOlderVisibleVersionWhenActiveIsArchived(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'run-fallback-test',
            'name' => 'Run Fallback Test',
            'content' => self::CONTENT_V1,
        ]));
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/run-fallback-test/versions', [
            'content' => self::CONTENT_V2,
        ]));
        // Archive v2 (the active one); v1 stays visible.
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/run-fallback-test/versions/2', [
            'is_visible' => false,
        ]));

        // Starting a run with no explicit version must resolve to v1 (the fallback), not 404.
        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/run-fallback-test/runs', []));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame(1, $body['data']['version'], 'startRun() should fall back to the highest visible version, consistent with the public browsing surface');
    }

    public function testStartRunReturns404WhenAllVersionsArchived(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'run-no-fallback-test',
            'name' => 'Run No Fallback Test',
            'content' => self::CONTENT_V1,
        ]));
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/run-no-fallback-test/versions/1', [
            'is_visible' => false,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/run-no-fallback-test/runs', []));

        self::assertSame(404, $status, (string) json_encode($body));
        self::assertSame('RTEST_VERSION_NOT_FOUND', $body['error']['code']);
    }
}
