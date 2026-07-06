<?php

declare(strict_types=1);

namespace Reflexometr\Http;

/**
 * Standard response envelope (documented in REQUIREMENTS/SHARED_CONSTANTS.md and
 * _API_CONTRACT/CONTRACT.md):
 *   success: { "data": <payload> }
 *   error:   { "error": { "code": "SOME_CODE", "details": {...}|null } }
 * Error responses never contain a hardcoded English string (CR-UI-02) — `code` is a stable
 * identifier the client maps to localized copy; `details` (when present) is structural data only
 * (ids, field names, numbers) never free text.
 */
final class Response
{
    /** @param mixed $data */
    public static function json($data, int $status = 200): array
    {
        return [$status, ['data' => $data]];
    }

    /** @param array<string,mixed>|null $details */
    public static function error(string $code, int $status, ?array $details = null): array
    {
        $body = ['code' => $code];
        if ($details !== null) {
            $body['details'] = $details;
        }
        return [$status, ['error' => $body]];
    }

    public static function send(int $status, array $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
