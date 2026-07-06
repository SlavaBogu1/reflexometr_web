<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\CategoryRepository;
use Reflexometr\Support\Validation;

/** CR-TEST-05: admin-only category CRUD (D7). Public read is exposed separately via RTestController::categories(). */
final class AdminCategoryController
{
    public static function create(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $name = Validation::requireString($request->all(), 'name');

        $repo = new CategoryRepository(Database::connection());
        if ($repo->findByName($name) !== null) {
            throw new ApiException(ErrorCode::CATEGORY_NAME_TAKEN, 409);
        }
        $id = $repo->create($name);
        return Response::json(['id' => $id, 'name' => $name], 201);
    }

    public static function update(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');
        $name = Validation::requireString($request->all(), 'name');

        $repo = new CategoryRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::CATEGORY_NOT_FOUND, 404);
        }
        $repo->rename($id, $name);
        return Response::json(['id' => $id, 'name' => $name]);
    }

    public static function delete(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');

        $repo = new CategoryRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::CATEGORY_NOT_FOUND, 404);
        }
        $repo->delete($id);
        return Response::json(['deleted' => true]);
    }
}
