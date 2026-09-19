<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Support\Validation;

final class AuthController
{
    public static function register(Request $request): array
    {
        $email = Validation::requireEmail($request->all());
        $password = Validation::requireString($request->all(), 'password', 8);

        $result = (new AuthService())->register($email, $password);
        return Response::json(self::publicPayload($result), 201);
    }

    public static function login(Request $request): array
    {
        $email = Validation::requireEmail($request->all());
        $password = Validation::requireString($request->all(), 'password', 1);

        $result = (new AuthService())->login($email, $password);
        return Response::json(self::publicPayload($result));
    }

    public static function logout(Request $request): array
    {
        $token = $request->bearerToken();
        if ($token !== null) {
            (new AuthService())->logout($token);
        }
        return Response::json(['logged_out' => true]);
    }

    public static function me(Request $request): array
    {
        $user = (new AuthService())->requireUser($request);
        return Response::json(self::profile($user));
    }

    /** @param array{user: array<string,mixed>, token: string} $result */
    private static function publicPayload(array $result): array
    {
        return [
            'user' => self::profile($result['user']),
            'token' => $result['token'],
        ];
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public static function profile(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'is_admin' => (bool) $user['is_admin'],
            'dominant_hand' => $user['dominant_hand'],
            'preferred_locale' => $user['preferred_locale'],
            // CR-AUTH-03: optional profile fields, freeform text, no uniqueness constraint.
            'real_name' => $user['real_name'] ?? null,
            'display_name' => $user['display_name'] ?? null,
        ];
    }
}
