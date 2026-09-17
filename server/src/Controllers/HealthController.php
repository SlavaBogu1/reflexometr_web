<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use PDO;
use Reflexometr\Database;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;

/**
 * CR-INFRA-02: post-deploy smoke-check target. No auth required — deployment/ops metadata, not
 * user data. Deliberately exercises a real DB round-trip (not just "PHP is alive") so a broken
 * deploy (missing .env, unreachable MySQL, bad credentials) fails the GitHub Actions workflow's
 * health-check step rather than reporting a false 200.
 */
final class HealthController
{
    public static function check(Request $request): array
    {
        try {
            Database::connection()->query('SELECT 1');
        } catch (\Throwable $e) {
            error_log('[reflexometr] health check DB failure: ' . $e->getMessage());
            return Response::error(ErrorCode::INTERNAL_ERROR, 503);
        }

        return Response::json(['status' => 'ok']);
    }
}
