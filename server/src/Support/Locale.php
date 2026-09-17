<?php

declare(strict_types=1);

namespace Reflexometr\Support;

use Reflexometr\Config;

/**
 * Supported locale codes (CR-UI-02) — kept in sync with REQUIREMENTS/SHARED_CONSTANTS.md.
 * `ru` is the default/fallback (CR-UI-13/D18 — reversed from the original `en` default); an
 * unrecognized code is rejected here (400 UNSUPPORTED_LOCALE) rather than silently stored, so
 * profiles never carry garbage.
 *
 * CR-INFRA-01: `SUPPORTED` is the full superset a deployment could ever offer; `enabled()` /
 * `default()` narrow that down per-deployment via `ENABLED_LOCALES` / `DEFAULT_LOCALE` (server
 * `.env`), following the existing Config env-override pattern (never a new config-file mechanism).
 */
final class Locale
{
    public const SUPPORTED = ['en', 'es', 'de', 'fr', 'zh-Hans', 'ru'];
    public const DEFAULT = 'ru';

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    /**
     * The deployment's enabled locale list: `ENABLED_LOCALES` (comma-separated) intersected
     * against `SUPPORTED`. Falls back to the full `SUPPORTED` list if unset/empty or if the
     * intersection is empty — a deployment can never resolve to zero available locales.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $raw = Config::get('ENABLED_LOCALES');
        if ($raw === null || trim($raw) === '') {
            return self::SUPPORTED;
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');
        $intersection = array_values(array_intersect(self::SUPPORTED, $requested));

        return $intersection === [] ? self::SUPPORTED : $intersection;
    }

    /**
     * The deployment's default locale: `DEFAULT_LOCALE`, falling back to `ru` (CR-UI-13/D18). If
     * the configured value isn't a member of `enabled()`, falls back to `ru` and logs a warning
     * rather than throwing — a misconfigured pair should never crash the request.
     */
    public static function default(): string
    {
        $configured = Config::get('DEFAULT_LOCALE');
        if ($configured === null || trim($configured) === '') {
            return self::DEFAULT;
        }

        $configured = trim($configured);
        if (!in_array($configured, self::enabled(), true)) {
            error_log(sprintf(
                'Locale::default(): DEFAULT_LOCALE "%s" is not in the enabled locale set (%s) — falling back to "%s"',
                $configured,
                implode(',', self::enabled()),
                self::DEFAULT,
            ));
            return self::DEFAULT;
        }

        return $configured;
    }

    private function __construct()
    {
    }
}
