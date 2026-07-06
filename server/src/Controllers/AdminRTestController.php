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

        $out = [];
        foreach ($rTests->listAll() as $rTest) {
            $versionRows = $versions->listForTest((int) $rTest['id']);
            $out[] = [
                'id' => (int) $rTest['id'],
                'slug' => $rTest['slug'],
                'name' => $rTest['name'],
                'description' => $rTest['description'],
                'category_id' => $rTest['category_id'] !== null ? (int) $rTest['category_id'] : null,
                'category_name' => $rTest['category_name'],
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
        $categoryId = isset($body['category_id']) && $body['category_id'] !== null
            ? Validation::requireInt($body, 'category_id')
            : null;

        $rawDescription = self::readDescriptionPayload($request);

        $result = (new ImportService())->importNewTest($slug, $name, $metaDescription, $categoryId, $rawDescription);
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
        $categoryProvided = array_key_exists('category_id', $body);
        $categoryId = $categoryProvided && $body['category_id'] !== null ? (int) $body['category_id'] : null;

        $rTests->updateMeta((int) $rTest['id'], $name, $description, $categoryId, $categoryProvided);

        return Response::json(['updated' => true]);
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
