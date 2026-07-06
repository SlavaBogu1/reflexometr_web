<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

/** CR-TEST-06: series mode on run-start — no change to per-run token/submission semantics. */
final class SeriesModeTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":2,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';

    private function seedTest(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => self::CONTENT_V1,
        ]));
    }

    /** @dataProvider validModes */
    public function testValidSeriesModesAccepted(string $mode): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', ['mode' => $mode]));
        self::assertSame(201, $status, (string) json_encode($body));
    }

    public static function validModes(): array
    {
        return [['single'], ['count:1'], ['count:5'], ['count:100'], ['until-quit']];
    }

    /** @dataProvider invalidModes */
    public function testInvalidSeriesModesRejected(string $mode): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', ['mode' => $mode]));
        self::assertSame(400, $status);
        self::assertSame('SERIES_MODE_INVALID', $body['error']['code']);
    }

    public static function invalidModes(): array
    {
        return [['count:0'], ['count:'], ['count:abc'], ['repeat'], ['']];
    }

    public function testEachRunInASeriesGetsItsOwnIndependentToken(): void
    {
        $this->seedTest();
        ['token' => $userToken] = $this->registerUser();

        [, $run1] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', ['mode' => 'count:3', 'series_id' => 'series-abc']));
        [, $run2] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', ['mode' => 'count:3', 'series_id' => 'series-abc']));

        self::assertNotSame($run1['data']['token'], $run2['data']['token']);
    }
}
