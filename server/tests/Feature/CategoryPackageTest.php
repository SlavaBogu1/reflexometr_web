<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

final class CategoryPackageTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":5,"inter_stimulus_delay_ms":{"min":500,"max":1500},"response_channels":["primary"],"timeout_ms":null}';

    public function testCategoryCrudIsAdminOnly(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('plain@test.local');

        [$status] = $this->dispatch($this->requestAs($userToken, 'POST', '/admin/categories', ['name' => 'audio']));
        self::assertSame(403, $status);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/categories', ['name' => 'audio']));
        self::assertSame(201, $status);
        $categoryId = $body['data']['id'];

        [$status, $public] = $this->dispatch($this->requestAs(null, 'GET', '/categories'));
        self::assertSame(200, $status);
        self::assertSame('audio', $public['data'][0]['name']);

        [$status] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/categories/{$categoryId}", ['name' => 'audio-visual']));
        self::assertSame(200, $status);

        [$status] = $this->dispatch($this->requestAs($adminToken, 'DELETE', "/admin/categories/{$categoryId}"));
        self::assertSame(200, $status);
    }

    public function testDuplicateCategoryNameRejected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/categories', ['name' => 'visual']));

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/categories', ['name' => 'visual']));
        self::assertSame(409, $status);
        self::assertSame('CATEGORY_NAME_TAKEN', $body['error']['code']);
    }

    public function testPackageCanSpanMultipleCategoriesAndTestsCanBelongToMultiplePackages(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [, $catA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/categories', ['name' => 'visual']));
        [, $catB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/categories', ['name' => 'audio']));

        [, $testA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'visual-test', 'name' => 'Visual', 'category_id' => $catA['data']['id'], 'content' => self::CONTENT_V1,
        ]));
        [, $testB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'audio-test', 'name' => 'Audio', 'category_id' => $catB['data']['id'], 'content' => self::CONTENT_V1,
        ]));

        [$status, $pilotPackage] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/packages', ['name' => 'Pilot Package']));
        self::assertSame(201, $status);
        $packageId = $pilotPackage['data']['id'];

        [, $testAInfo] = $this->dispatch($this->requestAs($adminToken, 'GET', '/r-tests/visual-test'));
        $testAId = $testAInfo['data']['id'];
        [, $testBInfo] = $this->dispatch($this->requestAs($adminToken, 'GET', '/r-tests/audio-test'));
        $testBId = $testBInfo['data']['id'];

        $this->dispatch($this->requestAs($adminToken, 'POST', "/admin/packages/{$packageId}/r-tests", ['r_test_id' => $testAId]));
        $this->dispatch($this->requestAs($adminToken, 'POST', "/admin/packages/{$packageId}/r-tests", ['r_test_id' => $testBId]));

        [$status, $packages] = $this->dispatch($this->requestAs(null, 'GET', '/packages'));
        self::assertSame(200, $status);
        $slugs = array_column($packages['data'][0]['r_tests'], 'slug');
        self::assertContains('visual-test', $slugs);
        self::assertContains('audio-test', $slugs);

        // A second package can also include visual-test (many-to-many).
        [, $secondPackage] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/packages', ['name' => 'Driver Package']));
        $this->dispatch($this->requestAs($adminToken, 'POST', "/admin/packages/{$secondPackage['data']['id']}/r-tests", ['r_test_id' => $testAId]));

        [, $show] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/visual-test'));
        $packageNames = array_column($show['data']['packages'], 'name');
        self::assertContains('Pilot Package', $packageNames);
        self::assertContains('Driver Package', $packageNames);
    }
}
