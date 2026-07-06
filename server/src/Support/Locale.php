<?php

declare(strict_types=1);

namespace Reflexometr\Support;

/**
 * Supported locale codes (CR-UI-02) — kept in sync with REQUIREMENTS/SHARED_CONSTANTS.md.
 * `en` is the default/fallback; an unrecognized code is rejected here (400
 * UNSUPPORTED_LOCALE) rather than silently stored, so profiles never carry garbage.
 */
final class Locale
{
    public const SUPPORTED = ['en', 'es', 'de', 'fr', 'zh-Hans'];
    public const DEFAULT = 'en';

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    private function __construct()
    {
    }
}
