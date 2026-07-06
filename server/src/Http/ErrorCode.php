<?php

declare(strict_types=1);

namespace Reflexometr\Http;

/**
 * Structured, locale-agnostic error codes (CR-UI-02): API responses never embed a hardcoded
 * English user-facing string. The client maps each code (and optional `details`, which is
 * always structural data — ids, numbers, field names — never free English text) to localized
 * copy. Adding a new failure mode means adding a new constant here, not a message string.
 */
final class ErrorCode
{
    // Generic / cross-cutting
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const NOT_FOUND = 'NOT_FOUND';
    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    public const ROUTE_NOT_FOUND = 'ROUTE_NOT_FOUND';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';
    public const UNSUPPORTED_LOCALE = 'UNSUPPORTED_LOCALE';

    // Auth (AUTH area)
    public const AUTH_REQUIRED = 'AUTH_REQUIRED';
    public const AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';
    public const AUTH_EMAIL_TAKEN = 'AUTH_EMAIL_TAKEN';
    public const AUTH_SESSION_EXPIRED = 'AUTH_SESSION_EXPIRED';
    public const ADMIN_REQUIRED = 'ADMIN_REQUIRED';

    // r-test library (CR-TEST-01 / CR-TEST-05)
    public const RTEST_NOT_FOUND = 'RTEST_NOT_FOUND';
    public const RTEST_SLUG_TAKEN = 'RTEST_SLUG_TAKEN';
    public const RTEST_VERSION_NOT_FOUND = 'RTEST_VERSION_NOT_FOUND';
    public const RTEST_VERSION_DUPLICATE = 'RTEST_VERSION_DUPLICATE';
    public const CATEGORY_NOT_FOUND = 'CATEGORY_NOT_FOUND';
    public const CATEGORY_NAME_TAKEN = 'CATEGORY_NAME_TAKEN';
    public const PACKAGE_NOT_FOUND = 'PACKAGE_NOT_FOUND';

    // Run token / submission (CR-TEST-02, D9, D11)
    public const RUN_TOKEN_INVALID = 'RUN_TOKEN_INVALID';
    public const RUN_TOKEN_EXPIRED = 'RUN_TOKEN_EXPIRED';
    public const RUN_TOKEN_ALREADY_USED = 'RUN_TOKEN_ALREADY_USED';
    public const TRIAL_LOG_INVALID = 'TRIAL_LOG_INVALID';
    public const SERIES_MODE_INVALID = 'SERIES_MODE_INVALID';

    // Stats (CR-STATS-01 / CR-STATS-02)
    public const VERSION_SCOPE_REQUIRED = 'VERSION_SCOPE_REQUIRED';

    private function __construct()
    {
    }
}
