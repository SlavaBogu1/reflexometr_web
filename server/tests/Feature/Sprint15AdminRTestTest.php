<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/**
 * Sprint 15 (CR-UI-18): tests for the new admin r-test management endpoints:
 * - DELETE /admin/r-tests/{slug} (success path + 409 RTEST_HAS_RESULTS)
 * - PATCH /admin/r-tests/{slug}/versions/{version} (is_visible toggle)
 * - GET /admin/r-tests — is_visible field present on version entries
 * - GET /r-tests — filters out tests with no visible active version
 */
final class Sprint15AdminRTestTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":10,"inter_stimulus_delay_ms":{"min":1000,"max":3000},"response_channels":["primary"],"timeout_ms":null}';

    // -------------------------------------------------------------------------
    // DELETE /admin/r-tests/{slug} — success path
    // -------------------------------------------------------------------------

    public function testDeleteRTestSucceedsWhenNoResults(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        // Create an r-test.
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'test-to-delete',
            'name' => 'Test To Delete',
            'content' => self::CONTENT_V1,
        ]));

        // Delete it — no results exist, so this should succeed.
        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'DELETE', '/admin/r-tests/test-to-delete'));

        self::assertSame(200, $status, (string) json_encode($body));
        self::assertTrue($body['data']['deleted']);

        // Confirm it's gone from the public list.
        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $slugs = array_column($listBody['data'], 'slug');
        self::assertNotContains('test-to-delete', $slugs, 'Deleted r-test should no longer appear in /r-tests');
    }

    public function testDeleteRTestReturns404ForUnknownSlug(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'DELETE', '/admin/r-tests/no-such-test'));

        self::assertSame(404, $status);
        self::assertSame('RTEST_NOT_FOUND', $body['error']['code']);
    }

    // -------------------------------------------------------------------------
    // DELETE /admin/r-tests/{slug} — 409 RTEST_HAS_RESULTS path
    // -------------------------------------------------------------------------

    public function testDeleteRTestReturns409WhenResultsExist(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken, 'user' => $user] = $this->registerUser('runner@test.local');

        // Create the r-test.
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'has-results',
            'name' => 'Has Results',
            'content' => self::CONTENT_V1,
        ]));

        // Start + submit a run to create a result row.
        [, $startBody] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/has-results/runs', []));
        $token  = $startBody['data']['token'];
        $trials = $this->buildTrials($startBody['data']['schedule']);
        Clock::advance(20_000); // advance wall clock so the submission passes the wall-clock floor check

        $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$token}/submit", [
            'trials'               => $trials,
            'client_started_at_ms' => 1000000000000,
        ]));

        // Now try to delete — must fail with 409.
        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'DELETE', '/admin/r-tests/has-results'));

        self::assertSame(409, $status, (string) json_encode($body));
        self::assertSame('RTEST_HAS_RESULTS', $body['error']['code']);
    }

    public function testNonAdminCannotDeleteRTest(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'admin-only-test',
            'name' => 'Admin Only',
            'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'DELETE', '/admin/r-tests/admin-only-test'));

        self::assertSame(403, $status);
        self::assertSame('ADMIN_REQUIRED', $body['error']['code']);
    }

    // -------------------------------------------------------------------------
    // PATCH /admin/r-tests/{slug}/versions/{version} — is_visible toggle
    // -------------------------------------------------------------------------

    public function testPatchVersionSetsIsVisibleFalse(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'toggle-test',
            'name' => 'Toggle Test',
            'content' => self::CONTENT_V1,
        ]));

        // Hide version 1.
        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/toggle-test/versions/1', [
            'is_visible' => false,
        ]));

        self::assertSame(200, $status, (string) json_encode($body));
        self::assertTrue($body['data']['updated']);

        // Admin list should reflect is_visible = false.
        [, $listBody] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests'));
        $testEntry   = null;
        foreach ($listBody['data'] as $entry) {
            if ($entry['slug'] === 'toggle-test') {
                $testEntry = $entry;
                break;
            }
        }
        self::assertNotNull($testEntry, 'r-test should still appear in admin list when hidden');
        self::assertFalse($testEntry['versions'][0]['is_visible'], 'is_visible should be false after patching');
    }

    public function testPatchVersionReturns404ForUnknownVersion(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'another-test',
            'name' => 'Another Test',
            'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/another-test/versions/99', [
            'is_visible' => false,
        ]));

        self::assertSame(404, $status);
        self::assertSame('VERSION_NOT_FOUND', $body['error']['code']);
    }

    public function testPatchVersionRejectsNonBoolIsVisible(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'validation-test',
            'name' => 'Validation Test',
            'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/validation-test/versions/1', [
            'is_visible' => 'yes',  // string, not bool
        ]));

        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }

    // -------------------------------------------------------------------------
    // GET /admin/r-tests — is_visible field present on version entries
    // -------------------------------------------------------------------------

    public function testAdminListIncludesIsVisibleOnVersionEntries(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'list-visibility-test',
            'name' => 'List Visibility Test',
            'content' => self::CONTENT_V1,
        ]));

        [, $listBody] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests'));

        $testEntry = null;
        foreach ($listBody['data'] as $entry) {
            if ($entry['slug'] === 'list-visibility-test') {
                $testEntry = $entry;
                break;
            }
        }

        self::assertNotNull($testEntry);
        self::assertArrayHasKey('is_visible', $testEntry['versions'][0]);
        self::assertTrue($testEntry['versions'][0]['is_visible'], 'New version should be visible by default');
    }

    // -------------------------------------------------------------------------
    // GET /r-tests — filters out tests with no visible active version
    // -------------------------------------------------------------------------

    public function testPublicListExcludesTestWithNoVisibleActiveVersion(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'invisible-test',
            'name' => 'Invisible Test',
            'content' => self::CONTENT_V1,
        ]));

        // Hide the only version.
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/invisible-test/versions/1', [
            'is_visible' => false,
        ]));

        // Public list should not include this test.
        [, $listBody] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $slugs = array_column($listBody['data'], 'slug');

        self::assertNotContains('invisible-test', $slugs, 'A test with no visible version should be hidden from public list');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Builds a minimal valid trial log for the given classic-schedule (trials[] array).
     * @param array<string,mixed> $schedule
     * @return array<int,array<string,mixed>>
     */
    private function buildTrials(array $schedule): array
    {
        $trialCount = (int) $schedule['trial_count'];
        $channels   = $schedule['response_channels'] ?? ['primary'];
        $trials     = $schedule['trials'];
        $out        = [];
        $t          = 0;
        for ($i = 0; $i < $trialCount; $i++) {
            $delayMs    = (int) $trials[$i]['delay_ms'];
            $t         += $delayMs;
            $stimulusAt = $t;
            $responses  = [];
            foreach ($channels as $ch) {
                $responses[$ch] = $stimulusAt + 300;
            }
            $out[] = [
                'index'       => $i,
                'stimulus_at' => $stimulusAt,
                'responses'   => $responses,
            ];
        }
        return $out;
    }
}
