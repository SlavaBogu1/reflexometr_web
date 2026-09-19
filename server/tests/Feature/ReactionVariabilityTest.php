<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Support\Clock;
use Reflexometr\Tests\TestCase;

/** CR-STATS-08: sd_ms/cv computed at submission time; comparison endpoint peer aggregate. */
final class ReactionVariabilityTest extends TestCase
{
    private const CONTENT_V1 = '{"trial_count":4,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}';
    private const TWO_HAND_CONTENT = '{"trial_count":3,"inter_stimulus_delay_ms":{"min":500,"max":500},"response_channels":["left","right"],"timeout_ms":2000}';

    private function seedTest(string $adminToken, string $slug, string $name, string $content): void
    {
        $this->dispatch($this->requestAs($adminToken, 'POST', '/admin/r-tests', [
            'slug' => $slug, 'name' => $name, 'content' => $content,
        ]));
    }

    public function testSdAndCvComputedFromKnownTrialLog(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();
        $this->seedTest($adminToken, 'simple-reaction', 'Simple Reaction', self::CONTENT_V1);

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(5000);

        // Reaction times: 200, 200, 300, 300 -> mean 250, sample sd (n-1) = sqrt(((-50)^2*2+(50)^2*2)/3)
        // = sqrt(10000/3) = 57.735...; cv = sd/mean.
        $cursor = 0.0;
        $reactions = [200.0, 200.0, 300.0, 300.0];
        $trials = [];
        foreach ($run['schedule']['trials'] as $i => $t) {
            if ($i >= 4) {
                break;
            }
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => ['primary' => $cursor + $reactions[$i]]];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));

        $expectedSd = sqrt(10000 / 3);
        $expectedCv = $expectedSd / 250.0;

        self::assertEqualsWithDelta($expectedSd, $body['data']['summary']['overall']['sd_ms'], 0.001);
        self::assertEqualsWithDelta($expectedCv, $body['data']['summary']['overall']['cv'], 0.0001);
    }

    public function testTwoHandComputesPerChannelSdAndCv(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();
        $this->seedTest($adminToken, 'two-hand-reaction', 'Two-Hand Reaction', self::TWO_HAND_CONTENT);

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/two-hand-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(10_000);

        $cursor = 0.0;
        $trials = [];
        // left: 200,200,200 (sd=0); right: 300,400,500 (varies)
        $leftTimes = [200.0, 200.0, 200.0];
        $rightTimes = [300.0, 400.0, 500.0];
        foreach ($run['schedule']['trials'] as $i => $t) {
            if ($i >= 3) {
                break;
            }
            $cursor += $t['delay_ms'];
            $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => [
                'left' => $cursor + $leftTimes[$i],
                'right' => $cursor + $rightTimes[$i],
            ]];
        }

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));

        self::assertEqualsWithDelta(0.0, $body['data']['summary']['channels']['left']['sd_ms'], 0.001);
        self::assertNotNull($body['data']['summary']['channels']['right']['sd_ms']);
        self::assertGreaterThan(0.0, $body['data']['summary']['channels']['right']['sd_ms']);
    }

    public function testSingleValidTrialLeavesSdAndCvNull(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $userToken] = $this->registerUser();
        $this->seedTest($adminToken, 'simple-reaction', 'Simple Reaction', '{"trial_count":1,"inter_stimulus_delay_ms":{"min":100,"max":100},"response_channels":["primary"],"timeout_ms":null}');

        [, $run] = $this->dispatch($this->requestAs($userToken, 'POST', '/r-tests/simple-reaction/runs', []));
        $run = $run['data'];
        Clock::advance(2000);
        $delay = $run['schedule']['trials'][0]['delay_ms'];
        $trials = [['index' => 0, 'stimulus_at' => $delay, 'responses' => ['primary' => $delay + 250]]];

        [$status, $body] = $this->dispatch($this->requestAs($userToken, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
        self::assertSame(201, $status, (string) json_encode($body));
        self::assertNull($body['data']['summary']['overall']['sd_ms']);
        self::assertNull($body['data']['summary']['overall']['cv']);
    }

    public function testComparisonExposesOwnSdCvAndPeerMedianExcludingNonApproved(): void
    {
        ['token' => $adminToken] = $this->registerAdmin();
        ['token' => $aliceToken] = $this->registerUser('alice-var@test.local');
        ['token' => $bobToken] = $this->registerUser('bob-var@test.local');
        $this->seedTest($adminToken, 'simple-reaction', 'Simple Reaction', self::CONTENT_V1);

        $submit = function (string $token, array $reactions) {
            [, $run] = $this->dispatch($this->requestAs($token, 'POST', '/r-tests/simple-reaction/runs', []));
            $run = $run['data'];
            Clock::advance(5000);
            $cursor = 0.0;
            $trials = [];
            foreach ($run['schedule']['trials'] as $i => $t) {
                if ($i >= count($reactions)) {
                    break;
                }
                $cursor += $t['delay_ms'];
                $trials[] = ['index' => $i, 'stimulus_at' => $cursor, 'responses' => ['primary' => $cursor + $reactions[$i]]];
            }
            [, $body] = $this->dispatch($this->requestAs($token, 'POST', "/r-tests/runs/{$run['token']}/submit", ['trials' => $trials]));
            return $body['data']['result_id'];
        };

        $aliceResult = $submit($aliceToken, [200.0, 200.0, 300.0, 300.0]); // sd ~57.7
        $bobResult = $submit($bobToken, [100.0, 500.0, 100.0, 500.0]); // sd larger

        // Before approval: Bob's comparison has no peers.
        [, $bobBefore] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertNull($bobBefore['data']['peer_sd_ms_median']);
        self::assertNotNull($bobBefore['data']['your_sd_ms']);

        $this->dispatch($this->requestAs($adminToken, 'PATCH', "/admin/results/{$aliceResult}", ['approval_status' => 'approved']));

        [, $bobAfter] = $this->dispatch($this->requestAs($bobToken, 'GET', "/results/{$bobResult}/comparison"));
        self::assertEqualsWithDelta(sqrt(10000 / 3), $bobAfter['data']['peer_sd_ms_median'], 0.01);
    }
}
