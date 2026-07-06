<?php

declare(strict_types=1);

namespace Reflexometr;

use Reflexometr\Controllers\AdminCategoryController;
use Reflexometr\Controllers\AdminPackageController;
use Reflexometr\Controllers\AdminRTestController;
use Reflexometr\Controllers\AuthController;
use Reflexometr\Controllers\ProfileController;
use Reflexometr\Controllers\RTestController;
use Reflexometr\Controllers\RunController;
use Reflexometr\Controllers\StatsController;
use Reflexometr\Http\Router;

/** Route table — the concrete list of what _API_CONTRACT/CONTRACT.md documents. */
final class Routes
{
    public static function register(Router $router): void
    {
        // Auth (infra — see SPRINT1_REPORT.md; not its own CR this sprint)
        $router->add('POST', '/auth/register', [AuthController::class, 'register']);
        $router->add('POST', '/auth/login', [AuthController::class, 'login']);
        $router->add('POST', '/auth/logout', [AuthController::class, 'logout']);
        $router->add('GET', '/auth/me', [AuthController::class, 'me']);

        // Profile (dominant_hand: CR-TEST-04, preferred_locale: CR-UI-02)
        $router->add('PATCH', '/profile', [ProfileController::class, 'update']);

        // Public r-test browsing (CR-TEST-01, CR-TEST-05)
        $router->add('GET', '/r-tests', [RTestController::class, 'list']);
        $router->add('GET', '/r-tests/{slug}', [RTestController::class, 'show']);
        $router->add('GET', '/categories', [RTestController::class, 'categories']);
        $router->add('GET', '/packages', [RTestController::class, 'packages']);

        // Admin r-test import/export (CR-TEST-01)
        $router->add('GET', '/admin/r-tests', [AdminRTestController::class, 'list']);
        $router->add('POST', '/admin/r-tests', [AdminRTestController::class, 'create']);
        $router->add('PATCH', '/admin/r-tests/{slug}', [AdminRTestController::class, 'updateMeta']);
        $router->add('POST', '/admin/r-tests/{slug}/versions', [AdminRTestController::class, 'importVersion']);
        $router->add('GET', '/admin/r-tests/{slug}/versions/{version}/export', [AdminRTestController::class, 'exportVersion']);

        // Admin categories & packages (CR-TEST-05)
        $router->add('POST', '/admin/categories', [AdminCategoryController::class, 'create']);
        $router->add('PATCH', '/admin/categories/{id}', [AdminCategoryController::class, 'update']);
        $router->add('DELETE', '/admin/categories/{id}', [AdminCategoryController::class, 'delete']);
        $router->add('POST', '/admin/packages', [AdminPackageController::class, 'create']);
        $router->add('PATCH', '/admin/packages/{id}', [AdminPackageController::class, 'update']);
        $router->add('DELETE', '/admin/packages/{id}', [AdminPackageController::class, 'delete']);
        $router->add('POST', '/admin/packages/{id}/r-tests', [AdminPackageController::class, 'addTest']);
        $router->add('DELETE', '/admin/packages/{id}/r-tests/{rTestId}', [AdminPackageController::class, 'removeTest']);

        // Run-token issuance + submission (CR-TEST-02, CR-TEST-06)
        $router->add('POST', '/r-tests/{slug}/runs', [RunController::class, 'start']);
        $router->add('POST', '/r-tests/runs/{token}/submit', [RunController::class, 'submit']);

        // Stats (CR-STATS-01, CR-STATS-02)
        $router->add('GET', '/r-tests/{slug}/versions/{version}/history', [StatsController::class, 'history']);
        $router->add('GET', '/results/{id}/comparison', [StatsController::class, 'comparison']);
    }
}
