<?php

declare(strict_types=1);

namespace Reflexometr\Support;

use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;

/**
 * Small structural-validation helpers. Failures always carry a field-name list in `details`,
 * never an English explanation (CR-UI-02) — the client renders its own localized message per
 * field/code combination.
 */
final class Validation
{
    /** @throws ApiException */
    public static function fail(array $fields, string $code = ErrorCode::VALIDATION_ERROR): never
    {
        throw new ApiException($code, 400, ['fields' => $fields]);
    }

    /** @param array<string,mixed> $body */
    public static function requireString(array $body, string $key, int $minLen = 1): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || mb_strlen($value) < $minLen) {
            self::fail([$key]);
        }
        return $value;
    }

    public static function requireEmail(array $body, string $key = 'email'): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            self::fail([$key]);
        }
        return $value;
    }

    public static function requireInt(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit(ltrim($value, '-')) ) {
            return (int) $value;
        }
        self::fail([$key]);
    }

    /** @param array<int,string> $allowed */
    public static function requireEnum(array $body, string $key, array $allowed): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::fail([$key]);
        }
        return $value;
    }

    /** Optional positive-int query param; null if absent. VALIDATION_ERROR if malformed. */
    public static function optionalPositiveIntQuery(Request $request, string $key): ?int
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw) || (int) $raw < 1) {
            self::fail([$key]);
        }
        return (int) $raw;
    }

    /** Optional non-negative-int query param; 0 if absent. VALIDATION_ERROR if malformed. */
    public static function nonNegativeIntQuery(Request $request, string $key): int
    {
        $raw = $request->query($key);
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!ctype_digit((string) $raw)) {
            self::fail([$key]);
        }
        return (int) $raw;
    }

    private function __construct()
    {
    }
}
