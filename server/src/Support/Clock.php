<?php

declare(strict_types=1);

namespace Reflexometr\Support;

/**
 * Wall-clock time source, in whole milliseconds since epoch. Centralized so tests can freeze/
 * advance time deterministically (run-token expiry, wall-clock consistency checks, D9).
 */
final class Clock
{
    private static ?int $frozenAtMs = null;

    public static function nowMs(): int
    {
        return self::$frozenAtMs ?? (int) round(microtime(true) * 1000);
    }

    public static function freeze(int $atMs): void
    {
        self::$frozenAtMs = $atMs;
    }

    public static function advance(int $ms): void
    {
        self::$frozenAtMs = self::nowMs() + $ms;
    }

    public static function unfreeze(): void
    {
        self::$frozenAtMs = null;
    }

    private function __construct()
    {
    }
}
