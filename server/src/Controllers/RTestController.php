<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\CategoryRepository;
use Reflexometr\Repositories\PackageRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;

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

        $categoryFilter = $request->query('category_id');
        $out = [];
        foreach ($rTests->listAll() as $rTest) {
            if ($categoryFilter !== null && (string) $rTest['category_id'] !== (string) $categoryFilter) {
                continue;
            }
            $active = $versions->findActiveForTest((int) $rTest['id']);
            $out[] = self::summarize($rTest, $active);
        }
        return Response::json($out);
    }

    public static function show(Request $request): array
    {
        $slug = (string) $request->param('slug');
        $db = Database::connection();
        $rTests = new RTestRepository($db);
        $versions = new RTestVersionRepository($db);

        $rTest = $rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }
        $active = $versions->findActiveForTest((int) $rTest['id']);
        $packages = (new PackageRepository($db))->listPackagesForTest((int) $rTest['id']);

        $payload = self::summarize($rTest, $active);
        $payload['packages'] = array_map(
            static fn (array $p): array => ['id' => (int) $p['id'], 'name' => $p['name']],
            $packages,
        );
        return Response::json($payload);
    }

    public static function categories(Request $request): array
    {
        $rows = (new CategoryRepository(Database::connection()))->listAll();
        return Response::json(array_map(
            static fn (array $c): array => ['id' => (int) $c['id'], 'name' => $c['name']],
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

    /** @param array<string,mixed> $rTest @param array<string,mixed>|null $activeVersion */
    private static function summarize(array $rTest, ?array $activeVersion): array
    {
        return [
            'id' => (int) $rTest['id'],
            'slug' => $rTest['slug'],
            'name' => $rTest['name'],
            'description' => $rTest['description'],
            'category_id' => $rTest['category_id'] !== null ? (int) $rTest['category_id'] : null,
            'current_version' => $activeVersion !== null ? (int) $activeVersion['version'] : null,
            'current_version_id' => $activeVersion !== null ? (int) $activeVersion['id'] : null,
        ];
    }
}
