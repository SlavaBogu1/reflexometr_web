<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Repositories\UserRepository;
use Reflexometr\Support\Locale;

/**
 * Self-service profile fields: `dominant_hand` (CR-TEST-04) and `preferred_locale` (CR-UI-02).
 * A user may only edit their own profile — no id parameter is accepted; it is always derived
 * from the authenticated session (D3 privacy rule).
 */
final class ProfileController
{
    public static function update(Request $request): array
    {
        $auth = new AuthService();
        $user = $auth->requireUser($request);
        $users = $auth->users();

        $body = $request->all();
        $updated = false;

        if (array_key_exists('dominant_hand', $body)) {
            $value = $body['dominant_hand'];
            if (!is_string($value) || !in_array($value, UserRepository::DOMINANT_HAND_VALUES, true)) {
                throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['dominant_hand']]);
            }
            $users->updateDominantHand((int) $user['id'], $value);
            $updated = true;
        }

        if (array_key_exists('preferred_locale', $body)) {
            $value = $body['preferred_locale'];
            if (!is_string($value) || !Locale::isSupported($value) || !in_array($value, Locale::enabled(), true)) {
                throw new ApiException(ErrorCode::UNSUPPORTED_LOCALE, 400, ['fields' => ['preferred_locale']]);
            }
            $users->updatePreferredLocale((int) $user['id'], $value);
            $updated = true;
        }

        // CR-AUTH-03: real_name/display_name — plain freeform strings, nullable (send null to
        // clear), max 100 chars, no uniqueness constraint (neither is a login identifier).
        if (array_key_exists('real_name', $body)) {
            $value = self::validateOptionalName($body['real_name'], 'real_name');
            $users->updateRealName((int) $user['id'], $value);
            $updated = true;
        }

        if (array_key_exists('display_name', $body)) {
            $value = self::validateOptionalName($body['display_name'], 'display_name');
            $users->updateDisplayName((int) $user['id'], $value);
            $updated = true;
        }

        if (!$updated) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, [
                'fields' => ['dominant_hand', 'preferred_locale', 'real_name', 'display_name'],
            ]);
        }

        $fresh = $users->findById((int) $user['id']);
        return Response::json(AuthController::profile($fresh));
    }

    /**
     * CR-AUTH-03: accepts a string (<=100 chars) or null (clears the field). Any other type, or a
     * string over 100 chars, is VALIDATION_ERROR.
     */
    private static function validateOptionalName(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || mb_strlen($value) > 100) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => [$field]]);
        }
        return $value;
    }
}
