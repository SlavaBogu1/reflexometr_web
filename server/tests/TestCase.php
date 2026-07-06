<?php

declare(strict_types=1);

namespace Reflexometr\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Reflexometr\Auth\AuthService;
use Reflexometr\Config;
use Reflexometr\Database;
use Reflexometr\Http\Request;
use Reflexometr\Http\Router;
use Reflexometr\Routes;
use Reflexometr\Support\Clock;

/**
 * Base test case: fresh in-memory SQLite DB + fresh Config per test (no shared state leaks
 * between tests). This is the local-development-only substitute for MySQL (production target
 * on HostGator, per D1) — see server/requirements/SPRINT1_REPORT.md.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::reset();
        Database::reset();
        Clock::unfreeze();

        Config::set('DB_DRIVER', 'sqlite');
        Config::set('DB_SQLITE_PATH', ':memory:');
        Config::set('ADMIN_EMAIL', 'admin@test.local');
        Config::set('SESSION_LIFETIME_SECONDS', '1209600');
        Config::set('RUN_TOKEN_TTL_SECONDS', '300');

        $db = Database::connection();
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sqlite.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            if ($statement !== '') {
                $db->exec($statement);
            }
        }
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        Database::reset();
        Config::reset();
        parent::tearDown();
    }

    /** @return array{user: array<string,mixed>, token: string} */
    protected function registerUser(string $email = 'user@test.local', string $password = 'password123'): array
    {
        return (new AuthService())->register($email, $password);
    }

    /** @return array{user: array<string,mixed>, token: string} */
    protected function registerAdmin(): array
    {
        return (new AuthService())->register('admin@test.local', 'password123');
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    protected function requestAs(?string $token, string $method, string $path, array $body = [], array $headers = []): Request
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return Request::fromArrays($method, $path, $headers, [], $body);
    }

    /** @return array{0:int,1:array} [status, bodyArray] — same shape the front controller sends. */
    protected function dispatch(Request $request): array
    {
        $router = new Router();
        Routes::register($router);
        return $router->dispatch($request);
    }
}
