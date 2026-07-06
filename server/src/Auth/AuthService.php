<?php

declare(strict_types=1);

namespace Reflexometr\Auth;

use Reflexometr\Config;
use Reflexometr\Database;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Repositories\SessionRepository;
use Reflexometr\Repositories\UserRepository;
use Reflexometr\Support\Rand;

/**
 * Minimal session-based auth. Not tied to a specific CR this sprint (no AUTH-area CR is
 * scheduled — see SPRINT1_REPORT.md), but is required groundwork: every task from SI-1.1 onward
 * assumes "the logged-in user" and D7's single hardcoded admin account. Mechanism: bearer token
 * (`Authorization: Bearer <token>`) backed by a server-side `sessions` table — chosen over
 * cookies to keep this a plain stateless-header JSON API with no CSRF-token machinery needed.
 */
final class AuthService
{
    private UserRepository $users;
    private SessionRepository $sessions;

    public function __construct()
    {
        $db = Database::connection();
        $this->users = new UserRepository($db);
        $this->sessions = new SessionRepository($db);
    }

    /** @return array{user: array<string,mixed>, token: string} */
    public function register(string $email, string $password): array
    {
        if ($this->users->findByEmail($email) !== null) {
            throw new ApiException(ErrorCode::AUTH_EMAIL_TAKEN, 409);
        }
        if (mb_strlen($password) < 8) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['password']]);
        }

        $adminEmail = Config::get('ADMIN_EMAIL', '');
        $isAdmin = $adminEmail !== '' && mb_strtolower($adminEmail) === mb_strtolower($email);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $userId = $this->users->create($email, $hash, $isAdmin);
        $user = $this->users->findById($userId);

        $token = Rand::token();
        $this->sessions->create($userId, $token);

        return ['user' => $user, 'token' => $token];
    }

    /** @return array{user: array<string,mixed>, token: string} */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            throw new ApiException(ErrorCode::AUTH_INVALID_CREDENTIALS, 401);
        }

        $token = Rand::token();
        $this->sessions->create((int) $user['id'], $token);

        return ['user' => $user, 'token' => $token];
    }

    public function logout(string $token): void
    {
        $this->sessions->delete($token);
    }

    /** @return array<string,mixed> @throws ApiException */
    public function requireUser(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(ErrorCode::AUTH_REQUIRED, 401);
        }
        $session = $this->sessions->findValidByToken($token);
        if ($session === null) {
            throw new ApiException(ErrorCode::AUTH_SESSION_EXPIRED, 401);
        }
        $this->sessions->touch((int) $session['id']);

        $user = $this->users->findById((int) $session['user_id']);
        if ($user === null) {
            throw new ApiException(ErrorCode::AUTH_REQUIRED, 401);
        }
        return $user;
    }

    /** @return array<string,mixed> @throws ApiException */
    public function requireAdmin(Request $request): array
    {
        $user = $this->requireUser($request);
        if ((int) $user['is_admin'] !== 1) {
            throw new ApiException(ErrorCode::ADMIN_REQUIRED, 403);
        }
        return $user;
    }

    public function users(): UserRepository
    {
        return $this->users;
    }
}
