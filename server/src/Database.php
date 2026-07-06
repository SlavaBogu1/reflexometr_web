<?php

declare(strict_types=1);

namespace Reflexometr;

use PDO;

/**
 * PDO connection factory. Production target is MySQL (HostGator cPanel, per D1).
 * When DB_DRIVER=sqlite this project falls back to SQLite as a **local-development-only**
 * substitute with an equivalent schema (see server/requirements/SPRINT1_REPORT.md) — never used
 * in production.
 */
final class Database
{
    private static ?PDO $connection = null;
    private static ?string $driver = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');
        self::$driver = $driver;

        if ($driver === 'mysql') {
            $host = Config::get('DB_HOST', 'localhost');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'reflexometr');
            $user = Config::get('DB_USER', '');
            $pass = Config::get('DB_PASSWORD', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $path = Config::get('DB_SQLITE_PATH', 'storage/dev.sqlite');
            if ($path !== ':memory:' && !str_starts_with($path, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
                // Relative path: resolve against the server/ root, not the current working directory.
                $path = dirname(__DIR__) . '/' . $path;
            }
            if ($path !== ':memory:') {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return self::$connection = $pdo;
    }

    public static function driver(): string
    {
        if (self::$driver === null) {
            self::connection();
        }
        return self::$driver;
    }

    /** Test isolation helper: force a fresh connection (e.g. a new in-memory sqlite db). */
    public static function reset(): void
    {
        self::$connection = null;
        self::$driver = null;
    }
}
