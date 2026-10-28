<?php

namespace Tests\Feature\Api;

use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\CreateDeposit;
use App\Data\Wallet\GatewayCallback;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_transfer_success_replay_and_conflict_contract(): void
    {
        [$source, $destination] = $this->wallets();
        $this->fund($source, 100);
        $payload = ['destination_wallet_id' => $destination, 'amount_minor' => 80];

        $first = $this->withHeader('Idempotency-Key', 'api-transfer')
            ->postJson("/api/v1/wallets/{$source}/transfers", $payload)
            ->assertOk()
            ->assertJsonPath('data.amount_minor', 80);
        $this->withHeader('Idempotency-Key', 'api-transfer')
            ->postJson("/api/v1/wallets/{$source}/transfers", $payload)
            ->assertOk()
            ->assertExactJson($first->json());
        $this->withHeader('Idempotency-Key', 'api-transfer')
            ->postJson("/api/v1/wallets/{$source}/transfers", [
                'destination_wallet_id' => $destination,
                'amount_minor' => 79,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
    }

    public function test_transfer_validation_and_insufficient_funds_contract(): void
    {
        [$source, $destination] = $this->wallets();

        $this->postJson("/api/v1/wallets/{$source}/transfers", [
            'destination_wallet_id' => $destination,
            'amount_minor' => 1,
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->withHeader('Idempotency-Key', 'self-transfer')
            ->postJson("/api/v1/wallets/{$source}/transfers", [
                'destination_wallet_id' => $source,
                'amount_minor' => 1,
            ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->withHeader('Idempotency-Key', 'insufficient')
            ->postJson("/api/v1/wallets/{$source}/transfers", [
                'destination_wallet_id' => $destination,
                'amount_minor' => 1,
            ])->assertUnprocessable()
            ->assertJsonPath('code', 'INSUFFICIENT_FUNDS')
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    /** @return array{string, string} */
    private function wallets(): array
    {
        return [
            DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id'),
            DB::table('wallets')->where('owner_id', 'demo-user-bob')->value('id'),
        ];
    }

    private function fund(string $walletId, int $amountMinor): void
    {
        $pending = app(CreateDeposit::class)->handle($walletId, $amountMinor, 'api-funding')->body()['data'];
        app(ConfirmDeposit::class)->handle(
            $pending['deposit_id'],
            new GatewayCallback($pending['gateway_reference'], $amountMinor),
        );
    }
}
