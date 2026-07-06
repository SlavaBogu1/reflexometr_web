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
