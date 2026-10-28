<?php

namespace Tests\Feature\Api;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DepositApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wallet.gateway_secret' => 'test-gateway-secret']);
        $this->seed(DatabaseSeeder::class);
    }

    public function test_deposit_create_read_and_callback_contract(): void
    {
        $walletId = $this->demoWalletId();
        $created = $this->withHeader('Idempotency-Key', 'api-deposit')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 125])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.amount_minor', 125);
        $depositId = $created->json('data.deposit_id');
        $gatewayReference = $created->json('data.gateway_reference');

        $this->getJson("/api/v1/deposits/{$depositId}")
            ->assertOk()
            ->assertJsonPath('data.id', $depositId)
            ->assertJsonPath('data.status', 'PENDING');

        $callback = [
            'gateway_reference' => $gatewayReference,
            'amount_minor' => 125,
        ];
        $this->withHeader('X-Gateway-Secret', 'wrong')
            ->postJson("/api/v1/gateway/deposits/{$depositId}/confirm", $callback)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'GATEWAY_FORBIDDEN')
            ->assertJsonPath('status', 403)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code']);
        $this->withHeader('X-Gateway-Secret', 'test-gateway-secret')
            ->postJson("/api/v1/gateway/deposits/{$depositId}/confirm", $callback)
            ->assertOk()
            ->assertJsonPath('data.status', 'SUCCEEDED');
        $this->withHeader('X-Gateway-Secret', 'test-gateway-secret')
            ->postJson("/api/v1/gateway/deposits/{$depositId}/fail", $callback)
            ->assertConflict()
            ->assertJsonPath('code', 'CALLBACK_CONFLICT')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code']);
    }

    public function test_deposit_validation_and_missing_resource_errors_are_stable(): void
    {
        $walletId = $this->demoWalletId();

        $this->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code', 'errors']);

        foreach ([0, -1, 1.5, '1e3', '9000000000000000001'] as $index => $amount) {
            $this->withHeader('Idempotency-Key', "invalid-{$index}")
                ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => $amount])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'VALIDATION_FAILED');
        }

        $this->getJson('/api/v1/deposits/'.Str::uuid7())
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('status', 404)
            ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance', 'code']);
    }

    public function test_changed_deposit_payload_returns_idempotency_conflict(): void
    {
        $walletId = $this->demoWalletId();
        $this->withHeader('Idempotency-Key', 'deposit-conflict')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 10])
            ->assertCreated();

        $this->withHeader('Idempotency-Key', 'deposit-conflict')
            ->postJson("/api/v1/wallets/{$walletId}/deposits", ['amount_minor' => 11])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    private function demoWalletId(): string
    {
        return DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id');
    }
}
