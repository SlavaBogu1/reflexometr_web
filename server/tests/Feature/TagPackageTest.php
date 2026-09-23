<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Http\Request;
use Reflexometr\Tests\TestCase;

/** CR-TEST-25 (Sprint 11): replaces CategoryPackageTest — many-to-many tag model. */
final class TagPackageTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":5,"inter_stimulus_delay_ms":{"min":500,"max":1500},"response_channels":["primary"],"timeout_ms":null}';

    /** @param array<string,string> $query */
    private function requestWithQuery(string $path, array $query): Request
    {
        return Request::fromArrays('GET', $path, [], $query);
    }

    public function testTagCrudIsAdminOnly(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('plain@test.local');

        [$status] = $this->dispatch($this->requestAs($userToken, 'POST', '/admin/tags', ['name' => 'audio']));
        self::assertSame(403, $status);

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'audio']));
        self::assertSame(201, $status);
        $tagId = $body['data']['id'];

        [$status, $public] = $this->dispatch($this->requestAs(null, 'GET', '/tags'));
        self::assertSame(200, $status);
        self::assertSame('audio', $public['data'][0]['name']);

        [$status] = $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/tags/{$tagId}", ['name' => 'audio-visual']));
        self::assertSame(200, $status);

        [$status] = $this->dispatch($this->requestAs($adminToken, 'DELETE', "/admin/tags/{$tagId}"));
        self::assertSame(200, $status);
    }

    public function testDuplicateTagNameRejected(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'visual']));

        [$status, $body] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'visual']));
        self::assertSame(409, $status);
        self::assertSame('TAG_NAME_TAKEN', $body['error']['code']);
    }

    public function testRTestCanCarryMultipleTagsAndBeFilteredByEach(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [, $tagA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'visual']));
        [, $tagB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'dynamic']));
        $tagAId = $tagA['data']['id'];
        $tagBId = $tagB['data']['id'];

        [$status, $created] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'multi-tag-test', 'name' => 'Multi Tag', 'tag_ids' => [$tagAId, $tagBId], 'content' => self::CONTENT_V1,
        ]));
        self::assertSame(201, $status, (string) json_encode($created));

        [, $show] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/multi-tag-test'));
        $tagNames = array_column($show['data']['tags'], 'name');
        self::assertContains('visual', $tagNames);
        self::assertContains('dynamic', $tagNames);
        self::assertCount(2, $show['data']['tags']);

        // Filtered browsing by either tag returns this r-test.
        [, $byTagA] = $this->dispatch($this->requestWithQuery('/r-tests', ['tag_id' => (string) $tagAId]));
        self::assertContains('multi-tag-test', array_column($byTagA['data'], 'slug'));
        [, $byTagB] = $this->dispatch($this->requestWithQuery('/r-tests', ['tag_id' => (string) $tagBId]));
        self::assertContains('multi-tag-test', array_column($byTagB['data'], 'slug'));

        // Deprecated category_id alias still filters the same way.
        [, $byLegacyAlias] = $this->dispatch($this->requestWithQuery('/r-tests', ['category_id' => (string) $tagAId]));
        self::assertContains('multi-tag-test', array_column($byLegacyAlias['data'], 'slug'));
    }

    public function testPatchTagIdsReplacesFullSetIncludingDownToZero(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        [, $tagA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'visual']));
        [, $tagB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'dynamic']));
        $tagAId = $tagA['data']['id'];
        $tagBId = $tagB['data']['id'];

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'patchable-test', 'name' => 'Patchable', 'tag_ids' => [$tagAId], 'content' => self::CONTENT_V1,
        ]));

        [, $before] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/patchable-test'));
        self::assertCount(1, $before['data']['tags']);

        // Replace with a different (larger) set.
        [$status] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/patchable-test', [
            'tag_ids' => [$tagAId, $tagBId],
        ]));
        self::assertSame(200, $status);
        [, $afterAdd] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/patchable-test'));
        self::assertCount(2, $afterAdd['data']['tags']);

        // Replace down to zero (empty array, not omitted) removes all tags without deleting the r-test.
        [$status] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/patchable-test', [
            'tag_ids' => [],
        ]));
        self::assertSame(200, $status);
        [$showStatus, $afterClear] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/patchable-test'));
        self::assertSame(200, $showStatus, 'r-test itself must still exist after clearing its tags');
        self::assertSame([], $afterClear['data']['tags']);

        // Omitting tag_ids entirely on a metadata-only PATCH leaves tags untouched.
        $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/patchable-test', [
            'tag_ids' => [$tagAId],
        ]));
        [$status] = $this->dispatch($this->requestAs($adminToken, 'PATCH', '/admin/r-tests/patchable-test', [
            'name' => 'Patchable Renamed',
        ]));
        self::assertSame(200, $status);
        [, $afterMetaOnlyPatch] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/patchable-test'));
        self::assertCount(1, $afterMetaOnlyPatch['data']['tags'], 'tag_ids omitted should not clear existing tags');
    }

    public function testRTestWithZeroTagsRendersEmptyArrayNotError(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        [$status, $created] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'no-tags-test', 'name' => 'No Tags', 'content' => self::CONTENT_V1,
        ]));
        self::assertSame(201, $status);

        [, $show] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests/no-tags-test'));
        self::assertSame([], $show['data']['tags']);

        [, $list] = $this->dispatch($this->requestAs(null, 'GET', '/r-tests'));
        $entry = current(array_filter($list['data'], static fn (array $t): bool => $t['slug'] === 'no-tags-test'));
        self::assertSame([], $entry['tags']);
    }

    public function testPackageCanSpanMultipleTagsAndTestsCanBelongToMultiplePackages(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();

        [, $tagA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'visual']));
        [, $tagB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/tags', ['name' => 'audio']));

        [, $testA] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'visual-test', 'name' => 'Visual', 'tag_ids' => [$tagA['data']['id']], 'content' => self::CONTENT_V1,
        ]));
        [, $testB] = $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'audio-test', 'name' => 'Audio', 'tag_ids' => [$tagB['data']['id']], 'content' => self::CONTENT_V1,
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
