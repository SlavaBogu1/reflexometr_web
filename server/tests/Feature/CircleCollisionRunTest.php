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

    /**
     * CR-TEST-28/SI-13.4 note: prior to Sprint 13, this test asserted the summary mean stayed
     * negative (signed) for an all-early run. That's now deliberately superseded for Circle
     * Collision specifically — abs_value_aggregation means the mean / primary_metric reflect
     * magnitude, not sign — while min/max (unsuffixed, see ResultSummaryService) still expose the
     * true signed value so a user can see they were early. This test still verifies the core
     * behavior its name promises (early response accepted, not rejected as a false start); the
     * abs-value-specific assertions live in testAbsValueAggregationAppliesToCircleCollisionMean
     * below.
     */
    public function testEarlyResponseIsAcceptedAndProducesNegativeSignedMinMax(): void
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
        // Abs-value aggregation: primary_metric/mean reflect magnitude (positive), not sign.
        self::assertEqualsWithDelta(100.0, $body['data']['primary_metric_ms'], 0.01);
        self::assertGreaterThan(0, $body['data']['summary']['overall']['mean_ms']);
        // But min/max (unsuffixed for this abs-value-aggregated family) still expose the true
        // signed value, so a user can see they were consistently early.
        self::assertEqualsWithDelta(-100.0, $body['data']['summary']['overall']['min'], 0.01);
        self::assertEqualsWithDelta(-100.0, $body['data']['summary']['overall']['max'], 0.01);
        self::assertArrayNotHasKey('min_ms', $body['data']['summary']['overall']);
        self::assertArrayNotHasKey('max_ms', $body['data']['summary']['overall']);
    }

    /**
     * CR-TEST-28/SI-13.4 acceptance criterion 5: a mixed set of early and late trials must not
     * net-cancel toward zero the way the old signed average did — the abs-value mean reflects
     * accuracy magnitude. Also confirms min/max still show the genuinely signed extremes
     * (early/late split) alongside the abs-value mean.
     */
    public function testAbsValueAggregationAppliesToCircleCollisionMean(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-simple', 'name' => 'Circle Collision Simple', 'content' => self::SIMPLE_CONTENT,
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-simple/runs', []));
        $schedule = $run['data']['schedule'];

        Clock::advance(60_000);

        // Trial 0: 200ms early (-200). Trial 1: 300ms late (+300). Trial 2: 100ms early (-100).
        // Old signed mean would be (-200+300-100)/3 = 0.0 (net-cancels near zero). Abs-value mean
        // is (200+300+100)/3 = 200.0 — must not net-cancel.
        $offsets = [-200, 300, -100];
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = [
                'index' => $i,
                'stimulus_at' => $cursor,
                'responses' => ['primary' => $cursor + $offsets[$i]],
            ];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['data']['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        self::assertEqualsWithDelta(200.0, $body['data']['summary']['overall']['mean_ms'], 0.01);
        self::assertEqualsWithDelta(200.0, $body['data']['primary_metric_ms'], 0.01);
        // Not net-cancelled near zero, which the old signed average would have produced.
        self::assertNotEqualsWithDelta(0.0, $body['data']['summary']['overall']['mean_ms'], 50.0);
        // Signed extremes still visible: most-early -200, most-late +300.
        self::assertEqualsWithDelta(-200.0, $body['data']['summary']['overall']['min'], 0.01);
        self::assertEqualsWithDelta(300.0, $body['data']['summary']['overall']['max'], 0.01);
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

    /**
     * CR-TEST-28/SI-13.3: full-stage-travel timeout — when the user never clicks before the
     * circles reach the far edge, the client now intentionally submits `responses.primary: null`
     * for that trial (legal per the existing "null allowed when timeout_ms is set" contract
     * clause). Verifies this is accepted (not rejected as MISSING_RESPONSE) and that the timed-out
     * trial is excluded from valid_count/mean the same way a missing response already is for every
     * other test type — explicit test, not an absence-of-change assumption (SPRINT_TASKS.md
     * explicitly calls out this path may not already be exercised by Circle Collision data).
     */
    public function testTimedOutTrialSubmitsNullResponseAndIsExcludedFromMean(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();

        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => 'circle-collision-simple', 'name' => 'Circle Collision Simple', 'content' => self::SIMPLE_CONTENT,
        ]));
        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/circle-collision-simple/runs', []));
        $schedule = $run['data']['schedule'];

        Clock::advance(60_000);

        // Trial 0: real (non-timeout) response, 100ms early. Trials 1-2: timed out (no click
        // before the far-edge timeout) -> null response, per the client's new intentional path.
        $trials = [];
        $cursor = 0.0;
        $used = array_slice($schedule['trials'], 0, $schedule['trial_count']);
        foreach ($used as $i => $t) {
            $cursor += $t['delay_ms'];
            $trials[] = [
                'index' => $i,
                'stimulus_at' => $cursor,
                'responses' => ['primary' => $i === 0 ? $cursor - 100 : null],
            ];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['data']['token']}/submit", [
            'trials' => $trials,
        ]));

        self::assertSame(201, $status, (string) json_encode($body));
        // Only the one real response counts toward valid_count/mean; the two nulls are excluded,
        // not coerced to 0 or otherwise pulled into the aggregate.
        self::assertSame(1, $body['data']['summary']['overall']['valid_count']);
        self::assertSame(2, $body['data']['summary']['channels']['primary']['timeouts']);
        // Abs-value aggregation (SI-13.4): mean reflects magnitude (100.0), not the raw sign.
        self::assertEqualsWithDelta(100.0, $body['data']['summary']['overall']['mean_ms'], 0.01);
        // Signed extreme still shows the true -100 (early), unaffected by abs-value aggregation.
        self::assertEqualsWithDelta(-100.0, $body['data']['summary']['overall']['min'], 0.01);
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
