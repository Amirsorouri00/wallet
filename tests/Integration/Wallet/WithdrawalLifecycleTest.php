<?php

namespace Tests\Integration\Wallet;

use App\Actions\Wallet\ApproveWithdrawal;
use App\Actions\Wallet\CreateWithdrawal;
use App\Actions\Wallet\RejectWithdrawal;
use App\Actions\Wallet\SettleWithdrawal;
use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\CommandOutcome;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\PostingLine;
use App\Exceptions\Wallet\WithdrawalConflict;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\WalletProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ConcurrentPhp;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class WithdrawalLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_request_approval_and_settlement_are_idempotent_and_debit_once(): void
    {
        $walletId = $this->fundedWallet('withdrawal-happy-user', 100);

        $requested = app(CreateWithdrawal::class)->handle($walletId, 80, 'request-happy');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];

        self::assertSame(CommandOutcome::CREATED, $requested->outcome());
        self::assertSame('PENDING', $requested->body()['data']['status']);
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'WithdrawalPending')->count());

        $approved = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-happy');
        $replayedApproval = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-happy');

        self::assertSame($approved->body(), $replayedApproval->body());
        self::assertSame('APPROVED', $approved->body()['data']['status']);
        self::assertSame(20, $this->latestBalance($walletId));
        self::assertSame(80, $this->latestBalance($approved->body()['data']['suspense_wallet_id']));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'WithdrawalApproved')->count());

        $settled = app(SettleWithdrawal::class)->handle($withdrawalId, 'settle-happy');
        $replayedSettlement = app(SettleWithdrawal::class)->handle($withdrawalId, 'settle-happy');

        self::assertSame($settled->body(), $replayedSettlement->body());
        self::assertSame('SETTLED', $settled->body()['data']['status']);
        self::assertSame(20, $this->latestBalance($walletId));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'WithdrawalSucceeded')->count());
    }

    public function test_pending_rejection_posts_no_ledger_transaction(): void
    {
        $walletId = $this->fundedWallet('withdrawal-reject-pending-user', 100);
        $requested = app(CreateWithdrawal::class)->handle($walletId, 80, 'request-reject-pending');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];

        $rejected = app(RejectWithdrawal::class)->handle(
            $withdrawalId,
            'reject-pending',
            'ADMIN_REJECTED',
        );

        self::assertSame('REJECTED', $rejected->body()['data']['status']);
        self::assertNull($rejected->body()['data']['approval_ledger_transaction_id']);
        self::assertNull($rejected->body()['data']['compensation_ledger_transaction_id']);
        self::assertSame(100, $this->latestBalance($walletId));
        self::assertSame(0, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'WithdrawalFailed')->count());
    }

    public function test_post_approval_rejection_uses_a_new_compensating_transaction(): void
    {
        $walletId = $this->fundedWallet('withdrawal-reject-approved-user', 100);
        $requested = app(CreateWithdrawal::class)->handle($walletId, 80, 'request-reject-approved');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];
        $approved = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-before-reject');

        $rejected = app(RejectWithdrawal::class)->handle(
            $withdrawalId,
            'reject-approved',
            'SETTLEMENT_CANCELLED',
        );

        self::assertSame('REJECTED', $rejected->body()['data']['status']);
        self::assertNotNull($rejected->body()['data']['compensation_ledger_transaction_id']);
        self::assertNotSame(
            $approved->body()['data']['approval_ledger_transaction_id'],
            $rejected->body()['data']['compensation_ledger_transaction_id'],
        );
        self::assertSame(100, $this->latestBalance($walletId));
        self::assertSame(0, $this->latestBalance($rejected->body()['data']['suspense_wallet_id']));
        self::assertSame(2, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_terminal_withdrawal_rejects_an_incompatible_admin_command(): void
    {
        $walletId = $this->fundedWallet('withdrawal-conflict-user', 100);
        $requested = app(CreateWithdrawal::class)->handle($walletId, 50, 'request-conflict');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];
        app(RejectWithdrawal::class)->handle($withdrawalId, 'reject-conflict', 'ADMIN_REJECTED');

        $this->expectException(WithdrawalConflict::class);
        app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-after-reject');
    }

    public function test_insufficient_funds_rejection_is_durable_for_the_same_approval_key(): void
    {
        $walletId = $this->fundedWallet('withdrawal-insufficient-user', 40);
        $requested = app(CreateWithdrawal::class)->handle($walletId, 80, 'request-insufficient');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];

        $rejected = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-insufficient');

        self::assertSame(CommandOutcome::REJECTED, $rejected->outcome());
        self::assertSame('INSUFFICIENT_FUNDS', $rejected->requiredError()->value);
        self::assertSame('rejected', DB::table('idempotency_records')
            ->where('operation', 'approve_withdrawal')
            ->where('idempotency_key', 'approve-insufficient')
            ->value('result_status'));

        $this->creditWallet($walletId, 40);

        $replayed = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-insufficient');
        $approved = app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-after-funding');

        self::assertSame($rejected->body(), $replayed->body());
        self::assertSame(CommandOutcome::REJECTED, $replayed->outcome());
        self::assertSame(CommandOutcome::SUCCEEDED, $approved->outcome());
        self::assertSame('APPROVED', $approved->body()['data']['status']);
        self::assertSame(0, $this->latestBalance($walletId));
    }

    public function test_concurrent_transfer_and_approval_cannot_overspend(): void
    {
        $walletId = $this->fundedWallet('withdrawal-concurrent-user', 100);
        $destinationId = app(WalletProvisioner::class)->create('user', 'withdrawal-concurrent-destination')->id;
        $requested = app(CreateWithdrawal::class)->handle($walletId, 80, 'request-concurrent');
        $withdrawalId = $requested->body()['data']['withdrawal_id'];
        $startingVersion = DB::table('wallet_balance_states')->where('wallet_id', $walletId)->max('version');

        $responses = ConcurrentPhp::runTransferAgainstWithdrawalApproval(
            ['source' => $walletId, 'destination' => $destinationId, 'amount' => 80, 'key' => 'race-transfer'],
            $withdrawalId,
            'race-approval',
        );

        $statuses = array_column($responses, 'status');
        sort($statuses);
        self::assertSame([200, 422], $statuses, json_encode($responses));
        self::assertSame(20, $this->latestBalance($walletId));
        self::assertSame(
            $startingVersion + 1,
            DB::table('wallet_balance_states')->where('wallet_id', $walletId)->max('version'),
        );
        self::assertSame(1, DB::table('ledger_transactions')
            ->whereIn('type', ['transfer', 'withdrawal'])
            ->count());
    }

    public function test_concurrent_approval_and_rejection_leave_a_consistent_rejected_state(): void
    {
        $walletId = $this->fundedWallet('withdrawal-approve-reject-race', 100);
        $withdrawalId = app(CreateWithdrawal::class)
            ->handle($walletId, 80, 'request-approve-reject-race')
            ->body()['data']['withdrawal_id'];

        $responses = ConcurrentPhp::runWithdrawalCommands([
            ['action' => 'approve', 'withdrawal' => $withdrawalId, 'key' => 'approve-race'],
            [
                'action' => 'reject',
                'withdrawal' => $withdrawalId,
                'key' => 'reject-race',
                'reason' => 'CONCURRENT_REJECTION',
            ],
        ]);

        self::assertNotContains(500, array_column($responses, 'status'), json_encode($responses));
        self::assertSame('REJECTED', DB::table('withdrawal_requests')->where('id', $withdrawalId)->value('status'));
        self::assertSame(100, $this->latestBalance($walletId));
        self::assertContains(DB::table('ledger_transactions')->where('type', 'withdrawal')->count(), [0, 2]);
    }

    public function test_concurrent_settlement_and_rejection_have_exactly_one_terminal_winner(): void
    {
        $walletId = $this->fundedWallet('withdrawal-settle-reject-race', 100);
        $withdrawalId = app(CreateWithdrawal::class)
            ->handle($walletId, 80, 'request-settle-reject-race')
            ->body()['data']['withdrawal_id'];
        app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-settle-reject-race');

        $responses = ConcurrentPhp::runWithdrawalCommands([
            ['action' => 'settle', 'withdrawal' => $withdrawalId, 'key' => 'settle-race'],
            [
                'action' => 'reject',
                'withdrawal' => $withdrawalId,
                'key' => 'terminal-reject-race',
                'reason' => 'CONCURRENT_TERMINAL_REJECTION',
            ],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);

        self::assertSame([200, 409], $statuses, json_encode($responses));
        $status = DB::table('withdrawal_requests')->where('id', $withdrawalId)->value('status');
        self::assertContains($status, ['SETTLED', 'REJECTED']);
        self::assertSame($status === 'SETTLED' ? 20 : 100, $this->latestBalance($walletId));
        self::assertSame($status === 'SETTLED' ? 1 : 2, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
    }

    public function test_compensation_outbox_failure_rolls_back_rejection_and_refund(): void
    {
        $walletId = $this->fundedWallet('withdrawal-compensation-rollback', 100);
        $withdrawalId = app(CreateWithdrawal::class)
            ->handle($walletId, 80, 'request-compensation-rollback')
            ->body()['data']['withdrawal_id'];
        app(ApproveWithdrawal::class)->handle($withdrawalId, 'approve-compensation-rollback');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION fail_test_withdrawal_failure_outbox()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event_type = 'WithdrawalFailed' THEN
                    RAISE EXCEPTION 'injected withdrawal outbox failure';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fail_test_withdrawal_failure_outbox_trigger
            BEFORE INSERT ON outbox_messages
            FOR EACH ROW EXECUTE FUNCTION fail_test_withdrawal_failure_outbox();
            SQL);

        try {
            app(RejectWithdrawal::class)->handle(
                $withdrawalId,
                'reject-compensation-rollback',
                'INJECTED_OUTBOX_FAILURE',
            );
            self::fail('The injected outbox failure did not abort withdrawal rejection.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('injected withdrawal outbox failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_test_withdrawal_failure_outbox_trigger ON outbox_messages');
            DB::unprepared('DROP FUNCTION IF EXISTS fail_test_withdrawal_failure_outbox()');
        }

        self::assertSame('APPROVED', DB::table('withdrawal_requests')->where('id', $withdrawalId)->value('status'));
        self::assertSame(20, $this->latestBalance($walletId));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(0, DB::table('idempotency_records')
            ->where('idempotency_key', 'reject-compensation-rollback')
            ->count());
        self::assertSame(0, DB::table('outbox_messages')->where('event_type', 'WithdrawalFailed')->count());
    }

    private function fundedWallet(string $ownerId, int $amountMinor): string
    {
        $walletId = app(WalletProvisioner::class)->create('user', $ownerId)->id;

        $this->creditWallet($walletId, $amountMinor);

        return $walletId;
    }

    private function creditWallet(string $walletId, int $amountMinor): void
    {
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
    }

    private function latestBalance(string $walletId): int
    {
        return DB::table('wallet_balance_states')
            ->where('wallet_id', $walletId)
            ->orderByDesc('version')
            ->value('balance_after_minor');
    }
}
