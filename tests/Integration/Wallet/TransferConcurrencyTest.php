<?php

namespace Tests\Integration\Wallet;

use App\Actions\Wallet\TransferFunds;
use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\CommandOutcome;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\PostingLine;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ConcurrentPhp;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class TransferConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    public function test_valid_transfer_commits_ledger_states_idempotency_and_outbox_atomically(): void
    {
        [$source, $destination] = $this->fundedUserPair();

        $response = app(TransferFunds::class)->handle($source, $destination, 80, 'valid-transfer');

        self::assertTrue($response->successful());
        self::assertSame(CommandOutcome::SUCCEEDED, $response->outcome());
        self::assertSame(80, $response->body()['data']['amount_minor']);
        self::assertSame(20, $this->latestBalance($source));
        self::assertSame(80, $this->latestBalance($destination));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(2, DB::table('ledger_entries')
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_transactions.type', 'transfer')
            ->count());
        self::assertSame(1, DB::table('idempotency_records')->where('result_status', 'succeeded')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());

        $replay = app(TransferFunds::class)->handle($source, $destination, 80, 'valid-transfer');
        self::assertSame($response->outcome(), $replay->outcome());
        self::assertSame($response->body(), $replay->body());
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());
    }

    public function test_validation_failures_do_not_reserve_an_idempotency_key(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $user = $provisioner->create('user', 'validation-user');
        $otherUser = $provisioner->create('user', 'validation-other');
        $system = $provisioner->create('system', 'gateway-clearing-00', true);
        $action = app(TransferFunds::class);

        foreach ([
            fn () => $action->handle($user->id, $user->id, 1, 'self-transfer'),
            fn () => $action->handle($system->id, $otherUser->id, 1, 'system-source'),
            fn () => $action->handle($user->id, $system->id, 1, 'system-destination'),
        ] as $invalidTransfer) {
            try {
                $invalidTransfer();
                self::fail('An invalid transfer unexpectedly succeeded.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('idempotency_records')->count());
            }
        }
    }

    public function test_insufficient_funds_is_a_durable_deterministic_rejection(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $source = $provisioner->create('user', 'empty-source')->id;
        $destination = $provisioner->create('user', 'empty-destination')->id;
        $action = app(TransferFunds::class);

        $rejected = $action->handle($source, $destination, 80, 'insufficient');
        self::assertFalse($rejected->successful());
        self::assertSame(CommandOutcome::REJECTED, $rejected->outcome());
        self::assertSame('INSUFFICIENT_FUNDS', $rejected->requiredError()->value);
        self::assertSame(1, DB::table('idempotency_records')->where('result_status', 'rejected')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'transfer')->count());

        $this->fund($source, 100);
        $replay = $action->handle($source, $destination, 80, 'insufficient');
        self::assertSame($rejected->body(), $replay->body());
        self::assertSame(100, $this->latestBalance($source));
        self::assertSame(0, $this->latestBalance($destination));
    }

    public function test_changed_payload_with_same_key_conflicts(): void
    {
        [$source, $destination] = $this->fundedUserPair();
        $other = app(WalletProvisioner::class)->create('user', 'other-destination')->id;
        $action = app(TransferFunds::class);
        $action->handle($source, $destination, 20, 'payload-conflict');

        $this->expectException(IdempotencyConflict::class);

        $action->handle($source, $other, 20, 'payload-conflict');
    }

    public function test_outbox_failure_rolls_back_ledger_states_and_idempotency(): void
    {
        [$source, $destination] = $this->fundedUserPair();

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_test_transfer_outbox()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event_type = 'TransferCompleted' THEN
                    RAISE EXCEPTION 'injected outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fail_test_transfer_outbox_trigger
            BEFORE INSERT ON outbox_messages
            FOR EACH ROW EXECUTE FUNCTION fail_test_transfer_outbox();
            SQL);

        try {
            app(TransferFunds::class)->handle($source, $destination, 80, 'outbox-failure');
            self::fail('The injected outbox failure did not abort the transfer.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('injected outbox failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_test_transfer_outbox_trigger ON outbox_messages');
            DB::unprepared('DROP FUNCTION IF EXISTS fail_test_transfer_outbox()');
        }

        self::assertSame(100, $this->latestBalance($source));
        self::assertSame(0, $this->latestBalance($destination));
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(0, DB::table('idempotency_records')->where('idempotency_key', 'outbox-failure')->count());
        self::assertSame(0, DB::table('outbox_messages')->count());
    }

    public function test_two_processes_cannot_overspend_one_wallet(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $source = $provisioner->create('user', 'concurrent-source')->id;
        $firstDestination = $provisioner->create('user', 'concurrent-destination-a')->id;
        $secondDestination = $provisioner->create('user', 'concurrent-destination-b')->id;
        $this->fund($source, 100);
        $startingVersion = $this->latestVersion($source);

        $responses = ConcurrentPhp::runTransfers([
            ['source' => $source, 'destination' => $firstDestination, 'amount' => 80, 'key' => 'race-a'],
            ['source' => $source, 'destination' => $secondDestination, 'amount' => 80, 'key' => 'race-b'],
        ]);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        self::assertSame([200, 422], $statuses, json_encode($responses));
        self::assertSame(20, $this->latestBalance($source));
        self::assertSame($startingVersion + 1, $this->latestVersion($source));
        self::assertSame(1, DB::table('wallet_balance_states')
            ->where('wallet_id', $source)
            ->where('version', '>', $startingVersion)
            ->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());
        self::assertSame(1, DB::table('idempotency_records')->where('result_status', 'succeeded')->count());
        self::assertSame(1, DB::table('idempotency_records')->where('result_status', 'rejected')->count());
    }

    public function test_concurrent_identical_idempotency_keys_create_one_mutation_and_return_one_result(): void
    {
        [$source, $destination] = $this->fundedUserPair();

        $responses = ConcurrentPhp::runTransfers([
            ['source' => $source, 'destination' => $destination, 'amount' => 80, 'key' => 'same-command'],
            ['source' => $source, 'destination' => $destination, 'amount' => 80, 'key' => 'same-command'],
        ]);

        self::assertSame([200, 200], array_column($responses, 'status'), json_encode($responses));
        self::assertSame($responses[0]['body'], $responses[1]['body']);
        self::assertSame(20, $this->latestBalance($source));
        self::assertSame(80, $this->latestBalance($destination));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(1, DB::table('idempotency_records')->where('idempotency_key', 'same-command')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());
    }

    public function test_opposing_transfers_lock_wallets_in_a_deterministic_order(): void
    {
        [$first, $second] = $this->fundedUserPair();
        $this->fund($second, 100);

        $responses = ConcurrentPhp::runTransfers([
            ['source' => $first, 'destination' => $second, 'amount' => 40, 'key' => 'opposing-a-b'],
            ['source' => $second, 'destination' => $first, 'amount' => 40, 'key' => 'opposing-b-a'],
        ]);

        self::assertSame([200, 200], array_column($responses, 'status'), json_encode($responses));
        self::assertSame(100, $this->latestBalance($first));
        self::assertSame(100, $this->latestBalance($second));
        self::assertSame(2, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(2, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());
    }

    /** @return array{string, string} */
    private function fundedUserPair(): array
    {
        $provisioner = app(WalletProvisioner::class);
        $source = $provisioner->create('user', 'funded-source')->id;
        $destination = $provisioner->create('user', 'funded-destination')->id;
        $this->fund($source, 100);

        return [$source, $destination];
    }

    private function fund(string $walletId, int $amountMinor): void
    {
        $clearing = DB::table('wallets')->where('owner_id', 'gateway-clearing-00')->value('id');

        if ($clearing === null) {
            $clearing = app(WalletProvisioner::class)
                ->create('system', 'gateway-clearing-00', true)
                ->id;
        }

        DB::transaction(fn () => app(LedgerPostingService::class)->post(new PostingCommand(
            type: LedgerTransactionType::DEPOSIT,
            referenceType: LedgerReferenceType::DEPOSIT,
            referenceId: (string) Str::uuid7(),
            posting: BalancedPosting::fromLines([
                new PostingLine($clearing, -$amountMinor),
                new PostingLine($walletId, $amountMinor),
            ]),
        )), attempts: 1);
    }

    private function latestBalance(string $walletId): int
    {
        return DB::table('wallet_balance_states')
            ->where('wallet_id', $walletId)
            ->orderByDesc('version')
            ->value('balance_after_minor');
    }

    private function latestVersion(string $walletId): int
    {
        return DB::table('wallet_balance_states')
            ->where('wallet_id', $walletId)
            ->max('version');
    }
}
