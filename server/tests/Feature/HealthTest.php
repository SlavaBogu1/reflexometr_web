<?php

declare(strict_types=1);

namespace Reflexometr\Tests\Feature;

use Reflexometr\Tests\TestCase;

/** CR-INFRA-02: GET /health is the post-deploy smoke-check target for the deploy workflow. */
final class HealthTest extends TestCase
{
    public function testHealthEndpointRequiresNoAuthAndReturnsOk(): void
    {
        [$status, $body] = $this->dispatch($this->requestAs(null, 'GET', '/health'));
        self::assertSame(200, $status);
        self::assertSame('ok', $body['data']['status']);
    }
}
