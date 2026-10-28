<?php

namespace Tests\Integration\Wallet;

use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\PostingLine;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    public function test_valid_ledger_and_projection_report_zero_drift(): void
    {
        [, $sourceWalletId] = $this->postTransfer();

        $exitCode = Artisan::call('wallets:reconcile', [
            '--wallet' => $sourceWalletId,
            '--since' => now()->subMinute()->toIso8601String(),
            '--limit' => 10,
            '--fail-on-drift' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No ledger or wallet-state drift detected.', Artisan::output());
    }

    public function test_corruption_is_reported_without_modifying_financial_rows(): void
    {
        [$transactionId, $sourceWalletId] = $this->postTransfer();

        DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ALL');
        DB::statement('ALTER TABLE wallet_balance_states DISABLE TRIGGER ALL');
        DB::table('ledger_entries')
            ->where('ledger_transaction_id', $transactionId)
            ->where('wallet_id', $sourceWalletId)
            ->update(['amount_minor' => -79]);
        DB::table('wallet_balance_states')
            ->where('wallet_id', $sourceWalletId)
            ->where('version', 0)
            ->delete();
        DB::statement('ALTER TABLE wallet_balance_states ENABLE TRIGGER ALL');
        DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ALL');

        $before = [
            'entries' => DB::table('ledger_entries')->get()->toJson(),
            'states' => DB::table('wallet_balance_states')->get()->toJson(),
        ];

        $exitCode = Artisan::call('wallets:reconcile', [
            '--limit' => 100,
            '--fail-on-drift' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('UNBALANCED_TRANSACTION', $output);
        self::assertStringContainsString('BALANCE_MISMATCH', $output);
        self::assertStringContainsString('MISSING_STATE_PROJECTION', $output);
        self::assertStringContainsString('BROKEN_STATE_CHAIN', $output);
        self::assertStringContainsString($transactionId, $output);
        self::assertStringContainsString($sourceWalletId, $output);
        self::assertSame($before['entries'], DB::table('ledger_entries')->get()->toJson());
        self::assertSame($before['states'], DB::table('wallet_balance_states')->get()->toJson());

        self::assertSame(0, Artisan::call('wallets:reconcile', ['--limit' => 1]));
        self::assertStringContainsString('Reconciliation found', Artisan::output());
    }

    /** @return array{string, string} */
    private function postTransfer(): array
    {
        $provisioner = app(WalletProvisioner::class);
        $source = $provisioner->create('system', 'gateway-clearing-00', true)->id;
        $destination = $provisioner->create('user', 'reconcile-destination')->id;
        $referenceId = (string) Str::uuid7();

        $result = DB::transaction(fn () => app(LedgerPostingService::class)->post(
            new PostingCommand(
                type: LedgerTransactionType::TRANSFER,
                referenceType: LedgerReferenceType::TRANSFER,
                referenceId: $referenceId,
                posting: BalancedPosting::fromLines([
                    new PostingLine($source, -80),
                    new PostingLine($destination, 80),
                ]),
            ),
        ), attempts: 1);

        return [$result->transactionId(), $source];
    }
}
