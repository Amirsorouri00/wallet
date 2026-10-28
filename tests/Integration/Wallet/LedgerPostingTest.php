<?php

namespace Tests\Integration\Wallet;

use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\PostingLine;
use App\Exceptions\Wallet\InvalidPosting;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    public function test_post_appends_double_entry_ledger_and_exact_wallet_states(): void
    {
        [$source, $destination] = $this->walletPair();
        $command = $this->command($source, $destination, 80);

        $result = DB::transaction(
            fn () => app(LedgerPostingService::class)->post($command),
            attempts: 1,
        );

        $transaction = DB::table('ledger_transactions')->where('id', $result->transactionId())->first();
        self::assertNotNull($transaction);
        self::assertSame('transfer', $transaction->type);
        self::assertSame(LedgerReferenceType::TRANSFER->value, $transaction->reference_type);

        $entries = DB::table('ledger_entries')
            ->where('ledger_transaction_id', $result->transactionId())
            ->orderBy('id')
            ->get()
            ->keyBy('wallet_id');
        self::assertCount(2, $entries);
        self::assertSame(-80, $entries[$source]->amount_minor);
        self::assertSame(80, $entries[$destination]->amount_minor);
        self::assertSame($entries[$source]->id, $result->entryIdsByWallet()[$source]);
        self::assertSame($entries[$destination]->id, $result->entryIdsByWallet()[$destination]);

        $states = DB::table('wallet_balance_states')
            ->whereIn('wallet_id', [$source, $destination])
            ->orderBy('version')
            ->get()
            ->groupBy('wallet_id');

        foreach ([$source => [-80, -80], $destination => [80, 80]] as $walletId => [$delta, $after]) {
            self::assertCount(2, $states[$walletId]);
            self::assertSame(0, $states[$walletId][0]->version);
            self::assertSame(0, $states[$walletId][0]->balance_after_minor);
            self::assertNull($states[$walletId][0]->ledger_entry_id);

            $next = $states[$walletId][1];
            self::assertSame(1, $next->version);
            self::assertSame($states[$walletId][0]->id, $next->previous_state_id);
            self::assertSame(0, $next->balance_before_minor);
            self::assertSame($delta, $next->delta_minor);
            self::assertSame($after, $next->balance_after_minor);
            self::assertSame($entries[$walletId]->id, $next->ledger_entry_id);
            self::assertSame($next->id, $result->stateIdsByWallet()[$walletId]);
        }
    }

    public function test_exception_before_second_state_rolls_back_transaction_entries_and_first_state(): void
    {
        [$source, $destination] = $this->walletPair();
        $referenceId = (string) Str::uuid7();

        DB::unprepared(sprintf(<<<'SQL'
            CREATE FUNCTION fail_test_second_wallet_state()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.wallet_id = '%s'::uuid AND NEW.version = 1 THEN
                    RAISE EXCEPTION 'injected state failure';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fail_test_second_wallet_state_trigger
            BEFORE INSERT ON wallet_balance_states
            FOR EACH ROW EXECUTE FUNCTION fail_test_second_wallet_state();
            SQL, $destination));

        try {
            DB::transaction(
                fn () => app(LedgerPostingService::class)->post(
                    $this->command($source, $destination, 80, $referenceId),
                ),
                attempts: 1,
            );
            self::fail('The injected second-state failure did not abort the posting.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('injected state failure', $exception->getMessage());
        }

        self::assertSame(0, DB::table('ledger_transactions')->where('reference_id', $referenceId)->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('wallet_balance_states')->where('version', '>', 0)->count());
    }

    public function test_duplicate_reference_cannot_post_twice(): void
    {
        [$source, $destination] = $this->walletPair();
        $referenceId = (string) Str::uuid7();
        $command = $this->command($source, $destination, 80, $referenceId);
        $service = app(LedgerPostingService::class);

        DB::transaction(fn () => $service->post($command), attempts: 1);

        try {
            DB::transaction(fn () => $service->post($command), attempts: 1);
            self::fail('A duplicate posting reference unexpectedly succeeded.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('ledger_transactions_reference_unique', $exception->getMessage());
        }

        self::assertSame(1, DB::table('ledger_transactions')->where('reference_id', $referenceId)->count());
        self::assertSame(2, DB::table('ledger_entries')->count());
        self::assertSame(2, DB::table('wallet_balance_states')->where('version', 1)->count());
    }

    public function test_user_debit_below_zero_inserts_nothing(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $source = $provisioner->create('user', 'empty-source')->id;
        $destination = $provisioner->create('user', 'empty-destination')->id;
        $referenceId = (string) Str::uuid7();

        try {
            DB::transaction(
                fn () => app(LedgerPostingService::class)->post(
                    $this->command($source, $destination, 1, $referenceId),
                ),
                attempts: 1,
            );
            self::fail('An overdrawn user posting unexpectedly succeeded.');
        } catch (InvalidPosting $exception) {
            self::assertStringContainsString('Insufficient balance', $exception->getMessage());
        }

        self::assertSame(0, DB::table('ledger_transactions')->where('reference_id', $referenceId)->count());
        self::assertSame(0, DB::table('ledger_entries')->count());
        self::assertSame(0, DB::table('wallet_balance_states')->where('version', '>', 0)->count());
    }

    public function test_system_clearing_wallet_may_become_negative(): void
    {
        [$source, $destination] = $this->walletPair();

        DB::transaction(
            fn () => app(LedgerPostingService::class)->post($this->command($source, $destination, 125)),
            attempts: 1,
        );

        self::assertSame(
            -125,
            DB::table('wallet_balance_states')
                ->where('wallet_id', $source)
                ->orderByDesc('version')
                ->value('balance_after_minor'),
        );
    }

    /** @return array{string, string} */
    private function walletPair(): array
    {
        $provisioner = app(WalletProvisioner::class);

        return [
            $provisioner->create('system', 'gateway-clearing-00', true)->id,
            $provisioner->create('user', 'posting-destination')->id,
        ];
    }

    private function command(
        string $source,
        string $destination,
        int $amountMinor,
        ?string $referenceId = null,
    ): PostingCommand {
        return new PostingCommand(
            type: LedgerTransactionType::TRANSFER,
            referenceType: LedgerReferenceType::TRANSFER,
            referenceId: $referenceId ?? (string) Str::uuid7(),
            posting: BalancedPosting::fromLines([
                new PostingLine($source, -$amountMinor),
                new PostingLine($destination, $amountMinor),
            ]),
        );
    }
}
