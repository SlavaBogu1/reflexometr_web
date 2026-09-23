<?php

declare(strict_types=1);

namespace Reflexometr;

use Reflexometr\Controllers\AdminPackageController;
use Reflexometr\Controllers\AdminResultController;
use Reflexometr\Controllers\AdminRTestController;
use Reflexometr\Controllers\AdminTagController;
use Reflexometr\Controllers\AuthController;
use Reflexometr\Controllers\ConfigController;
use Reflexometr\Controllers\HealthController;
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

        // Deployment metadata (CR-INFRA-01) — no auth required
        $router->add('GET', '/config/locales', [ConfigController::class, 'locales']);

        // Health check (CR-INFRA-02) — no auth required; post-deploy smoke-check target
        $router->add('GET', '/health', [HealthController::class, 'check']);

        // Public r-test browsing (CR-TEST-01, CR-TEST-05, CR-TEST-25)
        $router->add('GET', '/r-tests', [RTestController::class, 'list']);
        $router->add('GET', '/r-tests/{slug}', [RTestController::class, 'show']);
        $router->add('GET', '/tags', [RTestController::class, 'tags']);
        $router->add('GET', '/packages', [RTestController::class, 'packages']);

        // Admin r-test import/export (CR-TEST-01)
        $router->add('GET', '/admin/r-tests', [AdminRTestController::class, 'list']);
        $router->add('POST', '/admin/r-tests', [AdminRTestController::class, 'create']);
        $router->add('PATCH', '/admin/r-tests/{slug}', [AdminRTestController::class, 'updateMeta']);
        $router->add('POST', '/admin/r-tests/{slug}/versions', [AdminRTestController::class, 'importVersion']);
        $router->add('GET', '/admin/r-tests/{slug}/versions/{version}/export', [AdminRTestController::class, 'exportVersion']);

        // Admin tags & packages (CR-TEST-05, CR-TEST-25)
        $router->add('POST', '/admin/tags', [AdminTagController::class, 'create']);
        $router->add('PATCH', '/admin/tags/{id}', [AdminTagController::class, 'update']);
        $router->add('DELETE', '/admin/tags/{id}', [AdminTagController::class, 'delete']);
        $router->add('POST', '/admin/packages', [AdminPackageController::class, 'create']);
        $router->add('PATCH', '/admin/packages/{id}', [AdminPackageController::class, 'update']);
        $router->add('DELETE', '/admin/packages/{id}', [AdminPackageController::class, 'delete']);
        $router->add('POST', '/admin/packages/{id}/r-tests', [AdminPackageController::class, 'addTest']);
        $router->add('DELETE', '/admin/packages/{id}/r-tests/{rTestId}', [AdminPackageController::class, 'removeTest']);

        // Admin results approval queue (CR-AUTH-02, D19)
        $router->add('GET', '/admin/results', [AdminResultController::class, 'listPending']);
        $router->add('PATCH', '/admin/results/{id}', [AdminResultController::class, 'updateStatus']);

        // Run-token issuance + submission (CR-TEST-02, CR-TEST-06)
        $router->add('POST', '/r-tests/{slug}/runs', [RunController::class, 'start']);
        $router->add('POST', '/r-tests/runs/{token}/submit', [RunController::class, 'submit']);

        // Stats (CR-STATS-01, CR-STATS-02)
        $router->add('GET', '/r-tests/{slug}/versions/{version}/history', [StatsController::class, 'history']);
        $router->add('GET', '/results/{id}/comparison', [StatsController::class, 'comparison']);
    }
}
