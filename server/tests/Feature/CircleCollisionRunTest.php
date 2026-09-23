<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/**
 * CR-TEST-23/24 (Sprint 11): Circle Collision Simple/Complex — coincidence-anticipation tests.
 * Verifies the schedule-compile + allow_early_response validation-skip work end-to-end through
 * the real run/submit HTTP flow, and that classic discrete-stimulus tests are unaffected.
 */
final class CircleCollisionRunTest extends TestCase
{
    private const SIMPLE_CONTENT = <<<'JSON'
        {
          "trial_count": 3,
          "inter_stimulus_delay_ms": {"min": 500, "max": 800},
          "response_channels": ["primary"],
          "timeout_ms": 8000,
          "motion_duration_ms": {"min": 1000, "max": 5000},
          "circle_radius_px": 40,
          "allow_early_response": true
        }
        JSON;

    private const COMPLEX_CONTENT = <<<'JSON'
        {
          "trial_count": 3,
          "inter_stimulus_delay_ms": {"min": 500, "max": 800},
          "response_channels": ["primary"],
          "timeout_ms": 8000,
          "motion_speed_profile": {
            "start_speed_px_per_s": {"min": 200, "max": 500},
            "mid_speed_px_per_s": {"min": 200, "max": 500},
            "end_speed_px_per_s": {"min": 200, "max": 500},
            "travel_distance_px": 800
          },
          "circle_radius_px": 40,
          "allow_early_response": true
        }
        JSON;

    public function testCircleCollisionSimpleScheduleCarriesResolvedMotionFields(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-simple', 'name' => 'Circle Collision Simple', 'content' => self::SIMPLE_CONTENT,
        ]));

        [$status, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-simple/runs', []));
        self::assertSame(201, $status);

        $schedule = $run['data']['schedule'];
        self::assertTrue($schedule['allow_early_response']);
        self::assertSame(40, $schedule['circle_radius_px']);
        foreach ($schedule['trials'] as $trial) {
            self::assertGreaterThanOrEqual(1000, $trial['motion_duration_ms']);
            self::assertLessThanOrEqual(5000, $trial['motion_duration_ms']);
        }
        // D11: never the raw min/max range itself.
        self::assertArrayNotHasKey('motion_duration_ms', $schedule);
    }

    public function testEarlyResponseIsAcceptedAndProducesNegativeSignedSummary(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-simple', 'name' => 'Circle Collision Simple', 'content' => self::SIMPLE_CONTENT,
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-simple/runs', []));
        $schedule = $run['data']['schedule'];

        Clock::advance(60_000);

        // Every trial clicked well BEFORE its resolved motion_duration_ms elapses (early
        // anticipation) — must be accepted, not rejected as a false start.
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = [
                'index' => $i,
                'stimulus_at' => $cursor,
                'responses' => ['primary' => $cursor - 100], // 100ms BEFORE stimulus_at
            ];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['data']['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertEqualsWithDelta(-100.0, $body['data']['primary_metric_ms'], 0.01);
        self::assertLessThan(0, $body['data']['summary']['overall']['mean_ms']);
    }

    public function testLateResponseWellAfterCollisionStillSubmitsSuccessfully(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-simple', 'name' => 'Circle Collision Simple', 'content' => self::SIMPLE_CONTENT,
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-simple/runs', []));
        $schedule = $run['data']['schedule'];

        Clock::advance(60_000);

        // A very-late click (near the 8000ms timeout ceiling) must still submit — CR-TEST-21/
        // HF-02's "a test must never hang waiting for input" lesson, verified structurally here.
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = [
                'index' => $i,
                'stimulus_at' => $cursor,
                'responses' => ['primary' => $cursor + 7500], // very late, still within timeout_ms
            ];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['data']['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
    }

    public function testCircleCollisionComplexScheduleCarriesSpeedProfileAndPerCircleRadius(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-complex', 'name' => 'Circle Collision Complex', 'content' => self::COMPLEX_CONTENT,
        ]));

        [$status, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-complex/runs', []));
        self::assertSame(201, $status);

        $schedule = $run['data']['schedule'];
        self::assertTrue($schedule['allow_early_response']);
        // Complex variant: circle_radius_px is per-trial/per-circle, not schedule-level.
        self::assertArrayNotHasKey('circle_radius_px', $schedule);
        foreach ($schedule['trials'] as $trial) {
            self::assertArrayHasKey('motion_speed_profile', $trial);
            $duration = $trial['motion_speed_profile']['duration_ms'];
            self::assertGreaterThanOrEqual(1000, $duration);
            self::assertLessThanOrEqual(5000, $duration);

            self::assertArrayHasKey('circle_radius_px', $trial);
            foreach (['a', 'b'] as $circle) {
                self::assertGreaterThanOrEqual(32, $trial['circle_radius_px'][$circle]); // 40 - 20%
                self::assertLessThanOrEqual(48, $trial['circle_radius_px'][$circle]);    // 40 + 20%
            }
        }
    }

    public function testSimpleReactionStillRejectsEarlyResponseWhenFlagNotSet(): void
    {
        // Regression guard: a classic discrete-stimulus test (no allow_early_response in its
        // description) must still reject a before-stimulus response exactly as before.
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $content = '{"trial_count":2,"inter_stimulus_delay_ms":{"min":500,"max":800},"response_channels":["primary"],"timeout_ms":null}';
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'simple-reaction', 'name' => 'Simple Reaction', 'content' => $content,
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        $schedule = $run['data']['schedule'];
        self::assertArrayNotHasKey('allow_early_response', $schedule);

        Clock::advance(20_000);

        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => ['primary' => $cursor - 10]];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['data']['token']}/submit", [
            'trials' => $trials,
        ]));
        self::assertSame(400, $status);
        self::assertSame('REACTION_BEFORE_STIMULUS', $body['error']['details']['reason']);
    }
}
