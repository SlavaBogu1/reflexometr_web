<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\PackageRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Repositories\TagRepository;

/**
 * Public, read-only browsing surface — the test-taking client selects/displays the r-test's
 * current version from this data, never hardcoded client-side (CR-TEST-01). Never exposes a
 * version's raw imported description (D11) — only its metadata (id, version number, active flag).
 */
final class RTestController
{
    public static function list(Request $request): array
    {
        $db = Database::connection();
        $rTests = new RTestRepository($db);
        $versions = new RTestVersionRepository($db);
        $tags = new TagRepository($db);

        // CR-TEST-25 (v1.6): category_id -> tag_id (filters to r-tests carrying this one tag,
        // among possibly several). `category_id` kept as a deprecated alias since it's cheap to
        // support both — same filtering semantics, just against the new tag model instead of the
        // old single FK.
        $tagFilterRaw = $request->query('tag_id') ?? $request->query('category_id');
        $tagFilter = $tagFilterRaw !== null ? (int) $tagFilterRaw : null;

        $allTests = $rTests->listAll();
        $tagsByTest = $tags->tagsForTests(array_map(static fn (array $t): int => (int) $t['id'], $allTests));

        $out = [];
        foreach ($allTests as $rTest) {
            $testTags = $tagsByTest[(int) $rTest['id']];
            if ($tagFilter !== null && !in_array($tagFilter, array_column($testTags, 'id'), true)) {
                continue;
            }
            $active = $versions->findActiveForTest((int) $rTest['id']);
            $out[] = self::summarize($rTest, $active, $testTags);
        }
        return Response::json($out);
    }

    public static function show(Request $request): array
    {
        $slug = (string) $request->param('slug');
        $db = Database::connection();
        $rTests = new RTestRepository($db);
        $versions = new RTestVersionRepository($db);
        $tags = new TagRepository($db);

        $rTest = $rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }
        $active = $versions->findActiveForTest((int) $rTest['id']);
        $packages = (new PackageRepository($db))->listPackagesForTest((int) $rTest['id']);

        $payload = self::summarize($rTest, $active, $tags->tagsForTest((int) $rTest['id']));
        $payload['packages'] = array_map(
            static fn (array $p): array => ['id' => (int) $p['id'], 'name' => $p['name']],
            $packages,
        );
        return Response::json($payload);
    }

    /** CR-TEST-25 (v1.6): reads the same renamed r_test_tags table categories() used to. */
    public static function tags(Request $request): array
    {
        $rows = (new TagRepository(Database::connection()))->listAll();
        return Response::json(array_map(
            static fn (array $t): array => ['id' => (int) $t['id'], 'name' => $t['name']],
            $rows,
        ));
    }

    public static function packages(Request $request): array
    {
        $db = Database::connection();
        $packageRepo = new PackageRepository($db);
        $out = [];
        foreach ($packageRepo->listAll() as $package) {
            $tests = $packageRepo->listTestsForPackage((int) $package['id']);
            $out[] = [
                'id' => (int) $package['id'],
                'name' => $package['name'],
                'description' => $package['description'],
                'r_tests' => array_map(
                    static fn (array $t): array => ['id' => (int) $t['id'], 'slug' => $t['slug'], 'name' => $t['name']],
                    $tests,
                ),
            ];
        }
        return Response::json($out);
    }

    /**
     * @param array<string,mixed> $rTest @param array<string,mixed>|null $activeVersion
     * @param array<int,array<string,mixed>> $tags {id, name} pairs, possibly empty
     */
    private static function summarize(array $rTest, ?array $activeVersion, array $tags): array
    {
        return [
            'id' => (int) $rTest['id'],
            'slug' => $rTest['slug'],
            'name' => $rTest['name'],
            'description' => $rTest['description'],
            // CR-TEST-25 (v1.6): tags: [] replaces category_id/category_name (singular FK).
            'tags' => $tags,
            'current_version' => $activeVersion !== null ? (int) $activeVersion['version'] : null,
            'current_version_id' => $activeVersion !== null ? (int) $activeVersion['id'] : null,
        ];
    }
}
