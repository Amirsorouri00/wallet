<?php

namespace Tests\Feature\Api;

use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\PostingLine;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\WalletProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WithdrawalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_complete_withdrawal_http_contract_and_replays(): void
    {
        $walletId = $this->fundedWallet(100);

        $created = $this->withHeader('Idempotency-Key', 'api-withdrawal-create')
            ->postJson("/api/v1/wallets/{$walletId}/withdrawals", ['amount_minor' => 80])
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.amount_minor', 80);
        $withdrawalId = $created->json('data.withdrawal_id');

        $this->getJson("/api/v1/withdrawals/{$withdrawalId}")
            ->assertOk()
            ->assertJsonPath('data.withdrawal_id', $withdrawalId);

        $approved = $this->withHeader('Idempotency-Key', 'api-withdrawal-approve')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawalId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
        $approvalBody = $approved->json();

        $this->withHeader('Idempotency-Key', 'api-withdrawal-approve')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawalId}/approve")
            ->assertOk()
            ->assertExactJson($approvalBody);

        $this->withHeader('Idempotency-Key', 'api-withdrawal-settle')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawalId}/settle")
            ->assertOk()
            ->assertJsonPath('data.status', 'SETTLED');

        $this->withHeader('Idempotency-Key', 'api-withdrawal-reject-too-late')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawalId}/reject", ['reason' => 'TOO_LATE'])
            ->assertConflict()
            ->assertJsonPath('code', 'WITHDRAWAL_CONFLICT');
    }

    public function test_withdrawal_validation_is_stable(): void
    {
        $walletId = app(WalletProvisioner::class)->create('user', 'withdrawal-api-validation')->id;

        $this->postJson("/api/v1/wallets/{$walletId}/withdrawals", ['amount_minor' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->withHeader('Idempotency-Key', 'decimal-withdrawal')
            ->postJson("/api/v1/wallets/{$walletId}/withdrawals", ['amount_minor' => 10.5])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    private function fundedWallet(int $amountMinor): string
    {
        $walletId = app(WalletProvisioner::class)->create('user', 'withdrawal-api-user')->id;
        $clearingId = DB::table('wallets')->where('owner_id', 'gateway-clearing-00')->value('id');

        DB::transaction(fn () => app(LedgerPostingService::class)->post(new PostingCommand(
            type: LedgerTransactionType::DEPOSIT,
            referenceType: LedgerReferenceType::DEPOSIT,
            referenceId: (string) Str::uuid7(),
            posting: BalancedPosting::fromLines([
                new PostingLine($clearingId, -$amountMinor),
                new PostingLine($walletId, $amountMinor),
            ]),
        )), attempts: 1);

        return $walletId;
    }
}
