<?php

declare(strict_types=1);

namespace Reflexometr\Support;

final class Rand
{
    /** Cryptographically-random URL-safe token. */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Cryptographically-random integer in [min, max] inclusive — used for schedule compilation. */
    public static function intBetween(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }
        return random_int($min, $max);
    }

    private function __construct()
    {
    }
}
