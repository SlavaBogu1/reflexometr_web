<?php

declare(strict_types=1);

namespace Reflexometr;

/**
 * Minimal .env loader + typed config accessor. No external dependency (Composer
 * package footprint is deliberately near-zero for HostGator shared hosting).
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $envPath ??= dirname(__DIR__) . '/.env';
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip matching surrounding quotes, if any.
                if (strlen($value) >= 2 && $value[0] === $value[-1] && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        // Real environment variables (e.g. set by the HostGator/cPanel process
        // manager or a CI runner) take precedence over the .env file.
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }

    public static function getInt(string $key, int $default): int
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    /** Reset cached state — test isolation helper only. */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }

    /** Directly set/override a value — used by tests to force a config (e.g. sqlite path). */
    public static function set(string $key, string $value): void
    {
        self::load();
        self::$values[$key] = $value;
    }
}
