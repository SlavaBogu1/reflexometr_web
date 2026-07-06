<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

final class AdminRTestImportTest extends TestCase
{
    // Imported version payload (opaque, D11) — goes in the `content` field, distinct from the
    // r_test's own human-readable `description` metadata field.
    private const CONTENT_V1 = '{"trial_count":10,"inter_stimulus_delay_ms":{"min":1000,"max":3000},"response_channels":["primary"],"timeout_ms":null}';

    public function testNonAdminGets403OnImport(): void
    {
        ['token' => $userToken] = $this->registerUser('plain@test.local');

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/admin/r-tests', [
            'slug' => 'other-test',
            'name' => 'Other',
            'content' => self::CONTENT_V1,
        ]));

        self::assertSame(403, $status);
        self::assertSame('ADMIN_REQUIRED', $body['error']['code']);
    }

    public function testUnauthenticatedGets401OnImport(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'POST', '/admin/r-tests', [
            'slug' => 'other-test',
            'name' => 'Other',
            'content' => self::CONTENT_V1,
        ]));

        self::assertSame(401, $status);
        self::assertSame('AUTH_REQUIRED', $body['error']['code']);
    }

    public function testFullImportExportRoundTrip(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction',
            'name' => 'Simple Reaction',
            'description' => 'Public metadata description.',
            'content' => self::CONTENT_V1,
        ]));
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertSame('simple-reaction', $body['data']['r_test']['slug']);
        self::assertSame(1, $body['data']['version']);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests/simple-reaction/versions/1/export'));
        self::assertSame(200, $status);
        self::assertJsonStringEqualsJsonString(self::CONTENT_V1, $body['data']['description']);

        // List never leaks raw description content, only version metadata.
        [, $listBody] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests'));
        self::assertArrayNotHasKey('description', $listBody['data'][0]['versions'][0]);

        // Public browse metadata description is the human text, not the imported payload.
        [, $publicShow] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/simple-reaction'));
        self::assertSame('Public metadata description.', $publicShow['data']['description']);
    }

    public function testDuplicateSlugRejected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction Again', 'content' => self::CONTENT_V1,
        ]));

        self::assertSame(409, $status);
        self::assertSame('RTEST_SLUG_TAKEN', $body['error']['code']);
    }

    public function testMalformedDescriptionRejected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'broken-test',
            'name' => 'Broken',
            'content' => '{"trial_count": 0}',
        ]));

        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }

    public function testImportNewVersionKeepsPriorVersionUnchanged(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));

        $v2 = '{"trial_count":15,"inter_stimulus_delay_ms":{"min":800,"max":2500},"response_channels":["primary"],"timeout_ms":null}';
        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests/simple-reaction/versions', [
            'content' => $v2,
        ]));
        self::assertSame(201, $status);
        self::assertSame(2, $body['data']['version']);

        [, $v1Export] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests/simple-reaction/versions/1/export'));
        self::assertJsonStringEqualsJsonString(self::CONTENT_V1, $v1Export['data']['description']);

        [, $v2Export] = $this->dispatch($this->requestAs($adminToken, 'GET', '/admin/r-tests/simple-reaction/versions/2/export'));
        self::assertJsonStringEqualsJsonString($v2, $v2Export['data']['description']);

        // Public browse now reports current_version = 2 (the just-imported version is active).
        [, $publicShow] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/simple-reaction'));
        self::assertSame(2, $publicShow['data']['current_version']);
    }
}
