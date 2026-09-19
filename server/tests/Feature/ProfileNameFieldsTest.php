<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-AUTH-03: optional real_name/display_name profile fields. */
final class ProfileNameFieldsTest extends TestCase
{
    public function testRealNameAndDisplayNameDefaultToNull(): void
    {
        ['user' => $user] = $this->registerUser();
        self::assertNull($user['real_name']);
        self::assertNull($user['display_name']);
    }

    public function testSettingBothNamesPersistsAndRoundTripsOnAuthMe(): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', [
            'real_name' => 'Ada Lovelace',
            'display_name' => 'ada',
        ]));
        self::assertSame(200, $status);
        self::assertSame('Ada Lovelace', $body['data']['real_name']);
        self::assertSame('ada', $body['data']['display_name']);

        [, $me] = $this->dispatch($this->requestAs($token, 'GET', '/auth/me'));
        self::assertSame('Ada Lovelace', $me['data']['real_name']);
        self::assertSame('ada', $me['data']['display_name']);
    }

    public function testEitherFieldCanBeSetIndependently(): void
    {
        ['token' => $token] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['display_name' => 'solo']));
        self::assertSame(200, $status);
        self::assertSame('solo', $body['data']['display_name']);
        self::assertNull($body['data']['real_name']);
    }

    public function testOverLengthNameIsRejectedWithValidationError(): void
    {
        ['token' => $token] = $this->registerUser();
        $tooLong = str_repeat('a', 101);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['real_name' => $tooLong]));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertSame(['real_name'], $body['error']['details']['fields']);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['display_name' => $tooLong]));
        self::assertSame(400, $status);
        self::assertSame('VALIDATION_ERROR', $body['error']['code']);
        self::assertSame(['display_name'], $body['error']['details']['fields']);
    }

    public function testExactly100CharsIsAccepted(): void
    {
        ['token' => $token] = $this->registerUser();
        $exactly100 = str_repeat('a', 100);

        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['real_name' => $exactly100]));
        self::assertSame(200, $status);
        self::assertSame($exactly100, $body['data']['real_name']);
    }

    public function testNullClearsAPreviouslySetName(): void
    {
        ['token' => $token] = $this->registerUser();

        $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['real_name' => 'Someone']));
        [$status, $body] = $this->dispatch($this->requestAs($token, 'PATCH', '/profile', ['real_name' => null]));

        self::assertSame(200, $status);
        self::assertNull($body['data']['real_name']);
    }

    public function testNeitherNameFieldIsUniqueAcrossUsers(): void
    {
        ['token' => $token1] = $this->registerUser('dup1@test.local');
        ['token' => $token2] = $this->registerUser('dup2@test.local');

        [$status1, $body1] = $this->dispatch($this->requestAs($token1, 'PATCH', '/profile', [
            'real_name' => 'Same Name', 'display_name' => 'samehandle',
        ]));
        [$status2, $body2] = $this->dispatch($this->requestAs($token2, 'PATCH', '/profile', [
            'real_name' => 'Same Name', 'display_name' => 'samehandle',
        ]));

        self::assertSame(200, $status1);
        self::assertSame(200, $status2);
        self::assertSame('Same Name', $body1['data']['real_name']);
        self::assertSame('Same Name', $body2['data']['real_name']);
    }

    public function testNeitherNameFieldAppearsInComparisonPayload(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser('named@test.local');
        $this->dispatch($this->requestAs($userToken, 'PATCH', '/profile', [
            'real_name' => 'Secret Name', 'display_name' => 'secrethandle',
        ]));

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction',
            'content' => '{"trial_count":1,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}',
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(2000);
        $trials = [['index' => 0, 'stimulus_at' => 100, 'responses' => ['primary' => 300]]];
        [$submitStatus, $submit] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $submitStatus, (string) json_encode($submit));
        $resultId = $submit['data']['result_id'];

        [$status, $comparison] = $this->dispatch($this->requestAs($userToken, 'GET', "/results/{$resultId}/comparison"));
        self::assertSame(200, $status);
        $payload = json_encode($comparison);
        self::assertStringNotContainsString('Secret Name', $payload);
        self::assertStringNotContainsString('secrethandle', $payload);
    }
}
