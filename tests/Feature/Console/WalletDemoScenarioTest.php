<?php

namespace Tests\Feature\Console;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletDemoScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_scenario_exercises_retry_conflict_transfer_and_withdrawal_invariants(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('wallet:demo-scenario')
            ->expectsOutputToContain('Identical deposit replay: same response')
            ->expectsOutputToContain('Conflicting deposit replay: IDEMPOTENCY_CONFLICT')
            ->expectsOutputToContain('Reconciliation: clean')
            ->assertExitCode(0);

        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'deposit')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'transfer')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('type', 'withdrawal')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'DepositSucceeded')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'TransferCompleted')->count());
        self::assertSame(1, DB::table('outbox_messages')->where('event_type', 'WithdrawalSucceeded')->count());
    }
}
