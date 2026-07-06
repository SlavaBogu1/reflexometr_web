<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

final class AuthTest extends TestCase
{
    public function testRegisterThenMeReturnsProfile(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'POST', '/auth/register', [
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]));

        self::assertSame(201, $status);
        $token = $body['data']['token'];
        self::assertSame('alice@example.com', $body['data']['user']['email']);
        self::assertFalse($body['data']['user']['is_admin']);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'GET', '/auth/me'));
        self::assertSame(200, $status);
        self::assertSame('alice@example.com', $body['data']['email']);
    }

    public function testRegisteringTheConfiguredAdminEmailIsFlaggedAdmin(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'POST', '/auth/register', [
            'email' => 'admin@test.local', // matches TestCase's ADMIN_EMAIL
            'password' => 'password123',
        ]));

        self::assertSame(201, $status);
        self::assertTrue($body['data']['user']['is_admin']);
    }

    public function testDuplicateEmailRejected(): void
    {
        $this->registerUser('bob@example.com');

        [$status, $body] = $this->dispatch($this->requestAs(null, 'POST', '/auth/register', [
            'email' => 'bob@example.com',
            'password' => 'password123',
        ]));

        self::assertSame(409, $status);
        self::assertSame('AUTH_EMAIL_TAKEN', $body['error']['code']);
    }

    public function testLoginWithWrongPasswordRejected(): void
    {
        $this->registerUser('carol@example.com', 'correct-password');

        [$status, $body] = $this->dispatch($this->requestAs(null, 'POST', '/auth/login', [
            'email' => 'carol@example.com',
            'password' => 'wrong-password',
        ]));

        self::assertSame(401, $status);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $body['error']['code']);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/auth/me'));
        self::assertSame(401, $status);
        self::assertSame('AUTH_REQUIRED', $body['error']['code']);
    }

    public function testLogoutInvalidatesToken(): void
    {
        ['token' => $token] = $this->registerUser('dave@example.com');

        [$status] = $this->dispatch($this->requestAs($token, 'POST', '/auth/logout'));
        self::assertSame(200, $status);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'GET', '/auth/me'));
        self::assertSame(401, $status);
        self::assertSame('AUTH_SESSION_EXPIRED', $body['error']['code']);
    }

    public function testNoErrorResponseContainsFreeEnglishTextField(): void
    {
        [, $body] = $this->dispatch($this->requestAs(null, 'GET', '/auth/me'));
        // CR-UI-02: structured codes only — no "message" field with English prose.
        self::assertArrayNotHasKey('message', $body['error']);
        self::assertMatchesRegularExpression('/^[A-Z_]+$/', $body['error']['code']);
    }
}
