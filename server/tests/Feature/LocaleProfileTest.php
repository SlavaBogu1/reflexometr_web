<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

/** CR-UI-02: preferred_locale profile field + structured-code-only error responses. */
final class LocaleProfileTest extends TestCase
{
    public function testSettingSupportedLocalePersists(): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'zh-Hans']));
        self::assertSame(200, $status);
        self::assertSame('zh-Hans', $body['data']['preferred_locale']);

        [, $me] = $this->dispatch($this->requestAs($token, 'GET', '/auth/me'));
        self::assertSame('zh-Hans', $me['data']['preferred_locale']);
    }

    /**
     * CR-UI-07: `ru` must persist exactly like the other 5 supported locales — regression guard so
     * server/src/Support/Locale.php::SUPPORTED can never silently drift from
     * REQUIREMENTS/SHARED_CONSTANTS.md's locale list again.
     *
     * @dataProvider supportedLocaleProvider
     */
    public function testEachSupportedLocalePersists(string $locale): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => $locale]));
        self::assertSame(200, $status, "PATCH /profile with preferred_locale={$locale} should return 200");
        self::assertSame($locale, $body['data']['preferred_locale']);

        [, $me] = $this->dispatch($this->requestAs($token, 'GET', '/auth/me'));
        self::assertSame($locale, $me['data']['preferred_locale']);
    }

    /** @return array<string, array{0: string}> */
    public static function supportedLocaleProvider(): array
    {
        return [
            'en' => ['en'],
            'es' => ['es'],
            'de' => ['de'],
            'fr' => ['fr'],
            'zh-Hans' => ['zh-Hans'],
            'ru' => ['ru'],
        ];
    }

    public function testUnsupportedLocaleRejected(): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['preferred_locale' => 'xx-Zz']));
        self::assertSame(400, $status);
        self::assertSame('UNSUPPORTED_LOCALE', $body['error']['code']);
    }

    public function testPreferredLocaleDefaultsToNull(): void
    {
        ['user' => $user] = $this->registerUser();
        self::assertNull($user['preferred_locale']);
    }

    public function testDominantHandUpdateAndInvalidValueRejected(): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['dominant_hand' => 'left']));
        self::assertSame(200, $status);
        self::assertSame('left', $body['data']['dominant_hand']);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['dominant_hand' => 'sideways']));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
    }

    public function testDominantHandDefaultsToNoneRecorded(): void
    {
        ['user' => $user] = $this->registerUser();
        self::assertSame('none-recorded', $user['dominant_hand']);
    }
}
