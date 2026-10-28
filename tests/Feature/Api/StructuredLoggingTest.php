<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_request_log_has_operational_context_without_raw_idempotency_key(): void
    {
        $this->seed(DatabaseSeeder::class);
        $walletId = DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id');
        Log::spy();

        $this->withHeader('Idempotency-Key', 'never-log-this-raw-key')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 1])
            ->assertCreated();

        Log::shouldHaveReceived('info')->withArgs(
            static function (string $message, array $context) use ($walletId): bool {
                return $message === 'wallet-request'
                    && $context['operation'] === 'v1.wallets.deposits.store'
                    && $context['wallet_id'] === $walletId
                    && $context['outcome'] === 'success'
                    && $context['http_status'] === 201
                    && $context['retry_count'] === 0
                    && array_key_exists('transaction_id', $context)
                    && is_numeric($context['duration_ms'])
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'never-log-this-raw-key');
            },
        )->once();
    }
}
