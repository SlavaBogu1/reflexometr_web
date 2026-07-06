<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\PackageRepository;
use Reflexometr\Repositories\RTestRepository;
use Reflexometr\Support\Validation;

/** CR-TEST-05: admin-only package CRUD + r-test membership (many-to-many), D7. */
final class AdminPackageController
{
    public static function create(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $body = $request->all();
        $name = Validation::requireString($body, 'name');
        $description = is_string($body['description'] ?? null) ? $body['description'] : null;

        $id = (new PackageRepository(Database::connection()))->create($name, $description);
        return Response::json(['id' => $id, 'name' => $name, 'description' => $description], 201);
    }

    public static function update(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');
        $body = $request->all();
        $name = is_string($body['name'] ?? null) ? $body['name'] : null;
        $description = is_string($body['description'] ?? null) ? $body['description'] : null;

        $repo = new PackageRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::PACKAGE_NOT_FOUND, 404);
        }
        $repo->update($id, $name, $description);
        return Response::json(['updated' => true]);
    }

    public static function delete(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $id = (int) $request->param('id');

        $repo = new PackageRepository(Database::connection());
        if ($repo->findById($id) === null) {
            throw new ApiException(ErrorCode::PACKAGE_NOT_FOUND, 404);
        }
        $repo->delete($id);
        return Response::json(['deleted' => true]);
    }

    public static function addTest(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $packageId = (int) $request->param('id');
        $rTestId = Validation::requireInt($request->all(), 'r_test_id');

        $db = Database::connection();
        $packages = new PackageRepository($db);
        $rTests = new RTestRepository($db);
        if ($packages->findById($packageId) === null) {
            throw new ApiException(ErrorCode::PACKAGE_NOT_FOUND, 404);
        }
        if ($rTests->findById($rTestId) === null) {
            throw new ApiException(ErrorCode::RTEST_NOT_FOUND, 404);
        }
        $packages->addTest($packageId, $rTestId);
        return Response::json(['added' => true], 201);
    }

    public static function removeTest(Request $request): array
    {
        (new AuthService())->requireAdmin($request);
        $packageId = (int) $request->param('id');
        $rTestId = (int) $request->param('rTestId');

        (new PackageRepository(Database::connection()))->removeTest($packageId, $rTestId);
        return Response::json(['removed' => true]);
    }
}
