<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Support\Locale;

/**
 * Public deployment metadata (CR-INFRA-01) — no auth required. Distinct from user data: tells
 * the client which locales this specific deployment offers and which one is the default, so
 * `client/js/i18n.js` never has to hardcode a locale list that might not match a single-language
 * deployment's `ENABLED_LOCALES`/`DEFAULT_LOCALE` configuration.
 */
final class ConfigController
{
    public static function locales(Request $request): array
    {
        return Response::json([
            'enabled' => Locale::enabled(),
            'default' => Locale::default(),
        ]);
    }
}
