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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class HistoryQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
        DB::statement('SET LOCAL enable_seqscan = off');
    }

    public function test_wallet_history_plan_can_use_wallet_version_index(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $clearing = $provisioner->create('system', 'gateway-clearing-00', true)->id;
        $wallet = $provisioner->create('user', 'plan-history-user')->id;

        for ($index = 0; $index < 100; $index++) {
            DB::transaction(fn () => app(LedgerPostingService::class)->post(new PostingCommand(
                type: LedgerTransactionType::DEPOSIT,
                referenceType: LedgerReferenceType::DEPOSIT,
                referenceId: (string) Str::uuid7(),
                posting: BalancedPosting::fromLines([
                    new PostingLine($clearing, -1),
                    new PostingLine($wallet, 1),
                ]),
            )), attempts: 1);
        }

        $plan = $this->explain(<<<'SQL'
            SELECT state.version, state.delta_minor, state.balance_after_minor, state.created_at,
                   transaction.id, transaction.type, transaction.reference_type, transaction.reference_id
            FROM wallet_balance_states AS state
            JOIN ledger_entries AS entry ON entry.id = state.ledger_entry_id
            JOIN ledger_transactions AS transaction ON transaction.id = entry.ledger_transaction_id
            WHERE state.wallet_id = ? AND state.version > 0
            ORDER BY state.version DESC
            LIMIT 26
            SQL, [$wallet]);

        self::assertStringContainsString('wallet_balance_states_wallet_version_unique', $plan);
    }

    public function test_outbox_claim_plan_can_use_unpublished_partial_index(): void
    {
        for ($index = 0; $index < 50; $index++) {
            DB::table('outbox_messages')->insert([
                'id' => (string) Str::uuid7(),
                'event_type' => 'TransferCompleted',
                'aggregate_type' => 'transfer',
                'aggregate_id' => (string) Str::uuid7(),
                'payload' => '{}',
                'occurred_at' => now()->addMicroseconds($index),
                'available_at' => now()->subSecond(),
                'attempts' => 0,
            ]);
        }

        $plan = $this->explain(<<<'SQL'
            SELECT id
            FROM outbox_messages
            WHERE published_at IS NULL
              AND dead_lettered_at IS NULL
              AND available_at <= CURRENT_TIMESTAMP
              AND (leased_until IS NULL OR leased_until <= CURRENT_TIMESTAMP)
            ORDER BY available_at, occurred_at, id
            FOR UPDATE SKIP LOCKED
            LIMIT 10
            SQL);

        self::assertStringContainsString('outbox_messages_unpublished_available_index', $plan);
    }

    /** @param list<mixed> $bindings */
    private function explain(string $sql, array $bindings = []): string
    {
        $rows = DB::select('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
        $json = $rows[0]->{'QUERY PLAN'};

        return is_string($json) ? $json : json_encode($json, JSON_THROW_ON_ERROR);
    }
}
