<?php

namespace Tests\Integration\Wallet;

use App\Actions\Wallet\ConfirmDeposit;
use App\Actions\Wallet\CreateDeposit;
use App\Actions\Wallet\FailDeposit;
use App\Data\Wallet\GatewayCallback;
use App\Domain\Wallet\CommandOutcome;
use App\Exceptions\Wallet\CallbackConflict;
use App\Exceptions\Wallet\IdempotencyConflict;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ConcurrentPhp;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class DepositConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_creation_is_pending_and_moves_no_balance(): void
    {
        $walletId = $this->demoWalletId();
        $beforeStates = DB::table('wallet_balance_states')->count();

        $response = app(CreateDeposit::class)->handle($walletId, 125, 'deposit-create');

        self::assertTrue($response->successful());
        self::assertSame(CommandOutcome::CREATED, $response->outcome());
        self::assertSame('PENDING', $response->body()['data']['status']);
        self::assertSame(125, $response->body()['data']['amount_minor']);
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame($beforeStates, DB::table('wallet_balance_states')->count());

        $deposit = DB::table('deposits')->sole();
        self::assertSame($response->body()['data']['deposit_id'], $deposit->id);
        self::assertSame($walletId, $deposit->wallet_id);
        self::assertSame('PENDING', $deposit->status);
        self::assertTrue(DB::table('wallets')
            ->where('id', $deposit->clearing_wallet_id)
            ->where('owner_type', 'system')
            ->whereBetween('owner_id', ['gateway-clearing-00', 'gateway-clearing-63'])
            ->exists());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositPending')->count());
        self::assertSame(1, DB::table('idempotency_records')->where('result_status', 'succeeded')->count());
    }

    public function test_identical_creation_replays_and_changed_amount_conflicts(): void
    {
        $walletId = $this->demoWalletId();
        $action = app(CreateDeposit::class);
        $first = $action->handle($walletId, 125, 'deposit-replay');
        $replay = $action->handle($walletId, 125, 'deposit-replay');

        self::assertSame($first->outcome(), $replay->outcome());
        self::assertSame($first->body(), $replay->body());
        self::assertSame(1, DB::table('deposits')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositPending')->count());

        $this->expectException(IdempotencyConflict::class);

        $action->handle($walletId, 126, 'deposit-replay');
    }

    public function test_creation_validation_does_not_reserve_key(): void
    {
        $systemWalletId = DB::table('wallets')->where('owner_id', 'gateway-clearing-00')->value('id');

        try {
            app(CreateDeposit::class)->handle($systemWalletId, 125, 'system-deposit');
            self::fail('A deposit into a system wallet unexpectedly succeeded.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('idempotency_records')->count());
            self::assertSame(0, DB::table('deposits')->count());
        }
    }

    public function test_first_confirmation_credits_exactly_once(): void
    {
        $deposit = $this->pendingDeposit(125, 'confirm-once');
        $callback = new GatewayCallback($deposit['gateway_reference'], 125);

        $response = app(ConfirmDeposit::class)->handle($deposit['deposit_id'], $callback);

        self::assertSame(CommandOutcome::SUCCEEDED, $response->outcome());
        self::assertSame('SUCCEEDED', $response->body()['data']['status']);
        self::assertSame(125, $this->latestBalance($deposit['wallet_id']));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'deposit')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositSucceeded')->count());

        $stored = DB::table('deposits')->where('id', $deposit['deposit_id'])->first();
        self::assertSame('SUCCEEDED', $stored->status);
        self::assertNotNull($stored->ledger_transaction_id);
        self::assertNotNull($stored->resolved_at);
        self::assertNull($stored->failure_reason);

        $replay = app(ConfirmDeposit::class)->handle($deposit['deposit_id'], $callback);
        self::assertSame($response->body(), $replay->body());
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'deposit')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositSucceeded')->count());
    }

    public function test_first_failure_terminalizes_without_ledger_posting(): void
    {
        $deposit = $this->pendingDeposit(125, 'fail-once');
        $callback = new GatewayCallback($deposit['gateway_reference'], 125);

        $response = app(FailDeposit::class)->handle($deposit['deposit_id'], $callback);

        self::assertSame(CommandOutcome::SUCCEEDED, $response->outcome());
        self::assertSame('FAILED', $response->body()['data']['status']);
        self::assertSame(0, $this->latestBalance($deposit['wallet_id']));
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositFailed')->count());

        $stored = DB::table('deposits')->where('id', $deposit['deposit_id'])->first();
        self::assertSame('FAILED', $stored->status);
        self::assertNull($stored->ledger_transaction_id);
        self::assertNotNull($stored->resolved_at);
        self::assertSame('GATEWAY_REJECTED', $stored->failure_reason);

        $replay = app(FailDeposit::class)->handle($deposit['deposit_id'], $callback);
        self::assertSame($response->body(), $replay->body());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositFailed')->count());
    }

    public function test_opposite_terminal_callback_conflicts(): void
    {
        $succeeded = $this->pendingDeposit(50, 'terminal-success');
        $successCallback = new GatewayCallback($succeeded['gateway_reference'], 50);
        app(ConfirmDeposit::class)->handle($succeeded['deposit_id'], $successCallback);

        try {
            app(FailDeposit::class)->handle($succeeded['deposit_id'], $successCallback);
            self::fail('Failure unexpectedly replaced a successful deposit.');
        } catch (CallbackConflict) {
            self::assertSame('SUCCEEDED', DB::table('deposits')->where('id', $succeeded['deposit_id'])->value('status'));
        }

        $failed = $this->pendingDeposit(60, 'terminal-failure');
        $failureCallback = new GatewayCallback($failed['gateway_reference'], 60);
        app(FailDeposit::class)->handle($failed['deposit_id'], $failureCallback);

        try {
            app(ConfirmDeposit::class)->handle($failed['deposit_id'], $failureCallback);
            self::fail('Confirmation unexpectedly replaced a failed deposit.');
        } catch (CallbackConflict) {
            self::assertSame('FAILED', DB::table('deposits')->where('id', $failed['deposit_id'])->value('status'));
        }
    }

    public function test_missing_or_mismatched_callback_writes_nothing(): void
    {
        $deposit = $this->pendingDeposit(125, 'callback-mismatch');

        foreach ([
            new GatewayCallback((string) Str::uuid7(), 125),
            new GatewayCallback($deposit['gateway_reference'], 126),
        ] as $callback) {
            try {
                app(ConfirmDeposit::class)->handle($deposit['deposit_id'], $callback);
                self::fail('A mismatched callback unexpectedly succeeded.');
            } catch (CallbackConflict) {
                self::assertSame('PENDING', DB::table('deposits')->where('id', $deposit['deposit_id'])->value('status'));
            }
        }

        try {
            app(ConfirmDeposit::class)->handle(
                (string) Str::uuid7(),
                new GatewayCallback($deposit['gateway_reference'], 125),
            );
            self::fail('A callback for a missing deposit unexpectedly succeeded.');
        } catch (ModelNotFoundException) {
            self::assertSame(0, DB::table('ledger_transactions')->count());
        }

        self::assertSame(0, DB::table('outbox_messages')
            ->whereIn('event_type', ['DepositSucceeded', 'DepositFailed'])
            ->count());
        self::assertSame(0, $this->latestBalance($deposit['wallet_id']));
    }

    public function test_two_confirmation_processes_credit_once(): void
    {
        $deposit = $this->pendingDeposit(125, 'confirm-race');
        $callback = [
            'action' => 'confirm',
            'deposit' => $deposit['deposit_id'],
            'reference' => $deposit['gateway_reference'],
            'amount' => 125,
        ];

        $responses = ConcurrentPhp::runDepositCallbacks([$callback, $callback]);

        self::assertSame([200, 200], array_column($responses, 'status'), json_encode($responses));
        self::assertSame('SUCCEEDED', DB::table('deposits')->where('id', $deposit['deposit_id'])->value('status'));
        self::assertSame(125, $this->latestBalance($deposit['wallet_id']));
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'deposit')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositSucceeded')->count());
    }

    public function test_confirmation_and_failure_processes_have_one_terminal_winner(): void
    {
        $deposit = $this->pendingDeposit(125, 'opposite-race');
        $base = [
            'deposit' => $deposit['deposit_id'],
            'reference' => $deposit['gateway_reference'],
            'amount' => 125,
        ];

        $responses = ConcurrentPhp::runDepositCallbacks([
            ['action' => 'confirm', ...$base],
            ['action' => 'fail', ...$base],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);

        self::assertSame([200, 409], $statuses, json_encode($responses));
        $status = DB::table('deposits')->where('id', $deposit['deposit_id'])->value('status');
        self::assertContains($status, ['SUCCEEDED', 'FAILED']);
        self::assertSame(1, DB::table('outbox_messages')
            ->whereIn('event_type', ['DepositSucceeded', 'DepositFailed'])
            ->count());

        if ($status === 'SUCCEEDED') {
            self::assertSame(125, $this->latestBalance($deposit['wallet_id']));
            self::assertSame(1, DB::table('ledger_transactions')->where('type', 'deposit')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositSucceeded')->count());
        } else {
            self::assertSame(0, $this->latestBalance($deposit['wallet_id']));
            self::assertSame(0, DB::table('ledger_transactions')->where('type', 'deposit')->count());
            self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositFailed')->count());
        }
    }

    /** @return array<string, mixed> */
    private function pendingDeposit(int $amountMinor, string $key): array
    {
        return app(CreateDeposit::class)
            ->handle($this->demoWalletId(), $amountMinor, $key)
            ->body()['data'];
    }

    private function latestBalance(string $walletId): int
    {
        return DB::table('wallet_balance_states')
            ->where('wallet_id', $walletId)
            ->orderByDesc('version')
            ->value('balance_after_minor');
    }

    private function demoWalletId(): string
    {
        return DB::table('wallets')->where('owner_id', 'demo-user-alice')->value('id');
    }
}
