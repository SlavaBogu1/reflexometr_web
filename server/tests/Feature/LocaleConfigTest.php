<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Config;
use Reflexometr\Support\Locale;
use Reflexometr\Tests\TestCase;

/**
 * CR-INFRA-01: per-deployment locale scoping. `ENABLED_LOCALES`/`DEFAULT_LOCALE` (server `.env`)
 * narrow `Locale::enabled()`/`Locale::default()`, which in turn drive `GET /config/locales` and
 * `PATCH /profile`'s `preferred_locale` validation. These tests cover both the `Locale` helper
 * directly and the two HTTP surfaces that consume it.
 */
final class LocaleConfigTest extends TestCase
{
    // --- Locale::enabled() / Locale::default() -----------------------------------------------

    /** Regression guard: unset ENABLED_LOCALES must behave identically to pre-CR-INFRA-01 — all
     *  6 locales offered, default `ru` (CR-UI-13/D18 — default/fallback locale is `ru`, not `en`). */
    public function testUnsetEnabledLocalesReturnsFullSupportedSetAndDefaultRu(): void
    {
        self::assertSame(Locale::SUPPORTED, Locale::enabled());
        self::assertSame('ru', Locale::default());
    }

    public function testEmptyEnabledLocalesFallsBackToFullSupportedSet(): void
    {
        Config::set('ENABLED_LOCALES', '');
        self::assertSame(Locale::SUPPORTED, Locale::enabled());
    }

    public function testEnabledLocalesNarrowsToSingleLocale(): void
    {
        Config::set('ENABLED_LOCALES', 'en');
        self::assertSame(['en'], Locale::enabled());
    }

    public function testEnabledLocalesWithDefaultLocaleResolvesCorrectly(): void
    {
        Config::set('ENABLED_LOCALES', 'es,en');
        Config::set('DEFAULT_LOCALE', 'es');
        // enabled() preserves Locale::SUPPORTED's canonical order, not the env var's order.
        self::assertSame(['en', 'es'], Locale::enabled());
        self::assertSame('es', Locale::default());
    }

    /** A DEFAULT_LOCALE outside the enabled set falls back to `ru` rather than crashing. */
    public function testDefaultLocaleOutsideEnabledSetFallsBackToRu(): void
    {
        Config::set('ENABLED_LOCALES', 'es,fr');
        Config::set('DEFAULT_LOCALE', 'de');
        self::assertSame('ru', Locale::default());
    }

    /** An ENABLED_LOCALES value containing only unrecognized codes must never resolve to zero
     *  enabled locales — falls back to the full supported set instead. */
    public function testEnabledLocalesWithNoValidCodesFallsBackToFullSupportedSet(): void
    {
        Config::set('ENABLED_LOCALES', 'xx-Zz,not-a-locale');
        self::assertSame(Locale::SUPPORTED, Locale::enabled());
        self::assertNotEmpty(Locale::enabled());
    }

    public function testEnabledLocalesIgnoresUnknownCodesButKeepsValidOnes(): void
    {
        Config::set('ENABLED_LOCALES', 'en,not-a-locale,es');
        self::assertSame(['en', 'es'], Locale::enabled());
    }

    // --- GET /config/locales -------------------------------------------------------------------

    public function testConfigLocalesEndpointRequiresNoAuth(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/config/locales'));
        self::assertSame(200, $status);
        self::assertSame(Locale::SUPPORTED, $body['data']['enabled']);
        self::assertSame('ru', $body['data']['default']);
    }

    public function testConfigLocalesEndpointReflectsNarrowedEnabledLocales(): void
    {
        Config::set('ENABLED_LOCALES', 'en');
        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/config/locales'));
        self::assertSame(200, $status);
        self::assertSame(['en'], $body['data']['enabled']);
        // DEFAULT_LOCALE is unset here and the compiled-in default (`ru`) isn't a member of the
        // narrowed enabled() set, so default() falls back to enabled()[0] (CR-INFRA-03).
        self::assertSame('en', $body['data']['default']);
    }

    /** CR-INFRA-03 regression guard: unset DEFAULT_LOCALE + ENABLED_LOCALES narrowed to exclude
     *  the compiled-in default must never return a `default` outside `enabled` — asserted via
     *  membership, not a hardcoded literal, since the compiled-in default may change again later. */
    public function testUnsetDefaultLocaleWithNarrowedEnabledSetExcludingCompiledInDefaultStaysAMember(): void
    {
        self::assertNotContains(Locale::DEFAULT, ['en'], 'Test assumes the compiled-in default is not `en`; update the excluding set if that ever changes.');
        Config::set('ENABLED_LOCALES', 'en');

        $enabled = Locale::enabled();
        $default = Locale::default();
        self::assertContains($default, $enabled);

        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/config/locales'));
        self::assertSame(200, $status);
        self::assertContains($body['data']['default'], $body['data']['enabled']);
    }

    public function testConfigLocalesEndpointReflectsEnabledSetAndDefault(): void
    {
        Config::set('ENABLED_LOCALES', 'es,en');
        Config::set('DEFAULT_LOCALE', 'es');
        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/config/locales'));
        self::assertSame(200, $status);
        self::assertSame(['en', 'es'], $body['data']['enabled']);
        self::assertSame('es', $body['data']['default']);
    }

    // --- PATCH /profile preferred_locale narrowing ---------------------------------------------

    public function testProfileRejectsLocaleOutsideNarrowedEnabledSet(): void
    {
        Config::set('ENABLED_LOCALES', 'en');
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'es']));
        self::assertSame(400, $status);
        self::assertSame('UNSUPPORTED_LOCALE', $body['error']['code']);
    }

    public function testProfileAcceptsLocaleInsideNarrowedEnabledSet(): void
    {
        Config::set('ENABLED_LOCALES', 'en');
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'en']));
        self::assertSame(200, $status);
        self::assertSame('en', $body['data']['preferred_locale']);
    }

    public function testProfileRejectsLocaleOutsideEsEnEnabledSet(): void
    {
        Config::set('ENABLED_LOCALES', 'es,en');
        Config::set('DEFAULT_LOCALE', 'es');
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'de']));
        self::assertSame(400, $status);
        self::assertSame('UNSUPPORTED_LOCALE', $body['error']['code']);
    }

    public function testProfileStillRejectsGloballyUnsupportedLocaleWhenUnscoped(): void
    {
        // No ENABLED_LOCALES set — full 6-locale superset applies; a code outside that superset
        // entirely must still be rejected (regression guard for the pre-CR-INFRA-01 check).
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'xx-Zz']));
        self::assertSame(400, $status);
        self::assertSame('UNSUPPORTED_LOCALE', $body['error']['code']);
    }
}
