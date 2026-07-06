<?php

declare(strict_types=1);

namespace Reflexometr\Controllers;

use Reflexometr\Auth\AuthService;
use Reflexometr\Http\ApiException;
use Reflexometr\Http\ErrorCode;
use Reflexometr\Http\Request;
use Reflexometr\Http\Response;
use Reflexometr\Services\RunService;

/** CR-TEST-02 (run-token issuance/submission), CR-TEST-06 (series mode), D9, D11. */
final class RunController
{
    public static function start(Request $request): array
    {
        $user = (new AuthService())->requireUser($request);
        $slug = (string) $request->param('slug');

        $body = $request->all();
        $version = isset($body['version']) && $body['version'] !== null ? (int) $body['version'] : null;
        $mode = is_string($body['mode'] ?? null) ? $body['mode'] : 'single';
        $seriesId = is_string($body['series_id'] ?? null) ? $body['series_id'] : null;

        $result = (new RunService())->startRun((int) $user['id'], $slug, $version, $mode, $seriesId);

        return Response::json([
            'token' => $result['token'],
            'expires_at_ms' => $result['expires_at_ms'],
            'r_test_id' => $result['r_test_id'],
            'r_test_version_id' => $result['r_test_version_id'],
            'version' => $result['version'],
            'schedule' => $result['schedule'],
        ], 201);
    }

    public static function submit(Request $request): array
    {
        $user = (new AuthService())->requireUser($request);
        $token = (string) $request->param('token');

        $body = $request->all();
        $trials = $body['trials'] ?? null;
        if (!is_array($trials)) {
            throw new ApiException(ErrorCode::TRIAL_LOG_INVALID, 400, ['reason' => 'MISSING_TRIALS']);
        }
        $clientStartedAtMs = isset($body['client_started_at_ms']) ? (int) $body['client_started_at_ms'] : null;
        $dominantHand = is_string($body['dominant_hand'] ?? null) ? $body['dominant_hand'] : null;

        $result = (new RunService())->submitRun((string) $token, (int) $user['id'], $trials, $clientStartedAtMs, $dominantHand);

        return Response::json($result, 201);
    }
}
