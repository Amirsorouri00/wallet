<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_is_stateless(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('X-Request-Id')
            ->assertHeaderMissing('Set-Cookie');

        $this->withHeader('X-Request-Id', 'review-run-123')
            ->getJson('/api/health')
            ->assertHeader('X-Request-Id', 'review-run-123');
    }
}
