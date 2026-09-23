<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\TagRepository;
use Reflexometr\Support\Validation;

/**
 * CR-TEST-25 (Sprint 11): replaces AdminCategoryController — admin-only tag CRUD (D7), same
 * enforcement pattern. Public read is exposed separately via RTestController::tags().
 * `/admin/categories`/`/categories` are renamed to `/admin/tags`/`/tags` (clean rename, no
 * deprecated alias kept server-side — no external consumer existed yet per the v1.6 contract
 * note); `GET /r-tests?category_id=` is the one place a deprecated alias IS kept, since r-test
 * browsing already has real client traffic risk once ClientTeam integrates.
 */
final class AdminTagController
{
    public static function create(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $name = Validation::requireString($request->all(), 'name');

        $repo = new TagRepository(Database::connection());
        if ($repo->findByName($name) !== null) {
            throw new ApiException(ErrorCode::TAG_NAME_TAKEN, 409);
        }
        $id = $repo->create($name);
        return Response::json(['id' => $id, 'name' => $name], 201);
    }

    public static function update(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');
        $name = Validation::requireString($request->all(), 'name');

        $repo = new TagRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::TAG_NOT_FOUND, 404);
        }
        $repo->rename($id, $name);
        return Response::json(['id' => $id, 'name' => $name]);
    }

    public static function delete(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');

        $repo = new TagRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::TAG_NOT_FOUND, 404);
        }
        $repo->delete($id);
        return Response::json(['deleted' => true]);
    }
}
