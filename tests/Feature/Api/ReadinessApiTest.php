<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadinessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_verifies_database_schema_system_wallets_and_outbox_lag(): void
    {
        config()->set('wallet.readiness.max_outbox_lag_seconds', 60);
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => [
                    'database' => 'ok',
                    'schema' => 'ok',
                    'system_wallets' => 'ok',
                    'outbox' => 'ok',
                ],
                'outbox_lag_seconds' => 0,
            ]);

        DB::table('outbox_messages')->insert([
            'id' => (string) Str::uuid7(),
            'event_type' => 'TransferCompleted',
            'aggregate_type' => 'transfer',
            'aggregate_id' => (string) Str::uuid7(),
            'payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subSeconds(90),
            'available_at' => now()->subSeconds(90),
            'attempts' => 0,
        ]);

        $this->getJson('/api/ready')
            ->assertServiceUnavailable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('code', 'SERVICE_NOT_READY')
            ->assertJsonPath('retryable', true);
    }

    public function test_readiness_fails_when_required_system_wallets_are_missing(): void
    {
        $this->getJson('/api/ready')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'SERVICE_NOT_READY');
    }
}
