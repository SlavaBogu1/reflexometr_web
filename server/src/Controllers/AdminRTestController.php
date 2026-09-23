<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Repositories\RTestVersionRepository;
use Reflexometr\Repositories\TagRepository;
use Reflexometr\Services\ImportService;
use Reflexometr\Support\Validation;

/**
 * CR-TEST-01: admin-only import/export API. Enforces the single hardcoded admin account (D7) —
 * every action here calls AuthService::requireAdmin(), which 403s any non-admin session.
 */
final class AdminRTestController
{
    public static function list(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $db = Database::connection();
        $rTests = new RTestRepository($db);
        $versions = new RTestVersionRepository($db);
        $tags = new TagRepository($db);

        $allTests = $rTests->listAll();
        $tagsByTest = $tags->tagsForTests(array_map(static fn (array $t): int => (int) $t['id'], $allTests));

        $out = [];
        foreach ($allTests as $rTest) {
            $versionRows = $versions->listForTest((int) $rTest['id']);
            $out[] = [
                'id' => (int) $rTest['id'],
                'slug' => $rTest['slug'],
                'name' => $rTest['name'],
                'description' => $rTest['description'],
                // CR-TEST-25 (v1.6): tags: [] replaces category_id/category_name.
                'tags' => $tagsByTest[(int) $rTest['id']],
                'versions' => array_map(static fn (array $v): array => [
                    'id' => (int) $v['id'],
                    'version' => (int) $v['version'],
                    'is_active' => (bool) $v['is_active'],
                    'created_at' => $v['created_at'],
                    // Raw description content is deliberately omitted here — use the export
                    // endpoint to fetch it (D11 scopes "never raw to client" to the runtime
                    // client; this admin metadata list still keeps payload light by default).
                ], $versionRows),
            ];
        }

        return Response::json($out);
    }

    public static function create(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $body = $request->all();
        $slug = Validation::requireString($body, 'slug');
        $name = Validation::requireString($body, 'name');
        $metaDescription = is_string($body['description'] ?? null) ? $body['description'] : null;
        $tagIds = self::readTagIds($body);

        $rawDescription = self::readDescriptionPayload($request);

        $result = (new ImportService())->importNewTest($slug, $name, $metaDescription, $tagIds, $rawDescription);
        return Response::json([
            'r_test' => [
                'id' => (int) $result['r_test']['id'],
                'slug' => $result['r_test']['slug'],
                'name' => $result['r_test']['name'],
            ],
            'version' => (int) $result['version']['version'],
        ], 201);
    }

    public static function importVersion(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $slug = $request->param('slug');
        $rawDescription = self::readDescriptionPayload($request);

        $result = (new ImportService())->importNewVersion((string) $slug, $rawDescription);
        return Response::json(['version' => (int) $result['version']['version']], 201);
    }

    public static function exportVersion(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $slug = (string) $request->param('slug');
        $version = (int) $request->param('version');

        $raw = (new ImportService())->exportVersion($slug, $version);
        return Response::json(['slug' => $slug, 'version' => $version, 'description' => $raw]);
    }

    public static function updateMeta(Request $request): array
    {
        (new AuthService())->requireAdmin($request);

        $slug = (string) $request->param('slug');
        $db = Database::connection();
        $rTests = new RTestRepository($db);
        $rTest = $rTests->findBySlug($slug);
        if ($rTest === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }

        $body = $request->all();
        $name = is_string($body['name'] ?? null) ? $body['name'] : null;
        $description = is_string($body['description'] ?? null) ? $body['description'] : null;

        $rTests->updateMeta((int) $rTest['id'], $name, $description);

        // CR-TEST-25 (v1.6): tag_ids, when present, REPLACES the full tag set (not additive) —
        // omitted key means "leave tags alone"; an explicit empty array means "clear all tags".
        if (array_key_exists('tag_ids', $body)) {
            $tagIds = self::readTagIds($body) ?? [];
            (new TagRepository($db))->setTagsForTest((int) $rTest['id'], $tagIds);
        }

        return Response::json(['updated' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<int,int>|null null if tag_ids wasn't provided at all (distinct from an
     *     explicit empty array, which means "no tags").
     */
    private static function readTagIds(array $body): ?array
    {
        if (!array_key_exists('tag_ids', $body) || $body['tag_ids'] === null) {
            return null;
        }
        $raw = $body['tag_ids'];
        if (!is_array($raw) || array_filter($raw, static fn ($v) => !is_int($v) && !(is_string($v) && ctype_digit($v))) !== []) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['tag_ids']]);
        }
        return array_values(array_map('intval', $raw));
    }

    /** Accepts either a multipart `description_file` upload or an inline `content` JSON-text field. */
    private static function readDescriptionPayload(Request $request): string
    {
        $file = $request->file('description_file');
        if ($file !== null && is_string($file['tmp_name'] ?? null) && is_file($file['tmp_name'])) {
            $contents = file_get_contents($file['tmp_name']);
            if ($contents !== false && $contents !== '') {
                return $contents;
            }
        }
        // Inline fallback field is named `content` — deliberately distinct from `description`,
        // which on the create endpoint is the r_test's own human-readable metadata text, not the
        // imported opaque payload (they are different columns: r_tests.description vs.
        // r_test_versions.description).
        $inline = $request->input('content');
        if (is_string($inline) && $inline !== '') {
            return $inline;
        }
        throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['description_file']]);
    }
}
