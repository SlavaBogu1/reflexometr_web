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
            if (!is_string($value) || !Locale::isSupported($value)) {
                throw new ApiException(ErrorCode::UNSUPPORTED_LOCALE, 400, ['fields' => ['preferred_locale']]);
            }
            $users->updatePreferredLocale((int) $user['id'], $value);
            $updated = true;
        }

        if (!$updated) {
            throw new ApiException(ErrorCode::VALIDATION_ERROR, 400, ['fields' => ['dominant_hand', 'preferred_locale']]);
        }

        $fresh = $users->findById((int) $user['id']);
        return Response::json(AuthController::profile($fresh));
    }
}
