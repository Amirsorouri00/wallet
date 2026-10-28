<?php

namespace Tests\Integration\Wallet;

use App\Domain\Wallet\LedgerReferenceType;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class FinancialSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    public function test_only_the_contracted_financial_core_tables_are_created(): void
    {
        self::assertTrue(Schema::hasTable('wallets'));
        self::assertTrue(Schema::hasTable('ledger_transactions'));
        self::assertTrue(Schema::hasTable('ledger_entries'));
        self::assertTrue(Schema::hasTable('wallet_balance_states'));
        self::assertFalse(Schema::hasTable('users'));
    }

    public function test_duplicate_owner_identity_is_rejected(): void
    {
        $this->insertWallet('user', 'duplicate-owner');

        $this->assertConstraintViolation(
            'wallets_owner_identity_unique',
            fn () => $this->insertWallet('user', 'duplicate-owner'),
        );
    }

    public function test_user_wallet_cannot_allow_negative_balance(): void
    {
        $this->assertConstraintViolation(
            'wallets_owner_policy_check',
            fn () => $this->insertWallet('user', 'invalid-negative-policy', true),
        );
    }

    public function test_a_state_chain_cannot_branch_by_reusing_a_predecessor(): void
    {
        $wallet = $this->insertWalletWithInitialState();
        $this->insertNextState($wallet, 10);

        $this->assertConstraintViolation(
            'wallet_balance_states_',
            fn () => $this->insertNextState($wallet, 20),
        );
    }

    public function test_a_state_version_cannot_skip_its_predecessor_version(): void
    {
        $wallet = $this->insertWalletWithInitialState();
        $entryId = $this->insertLedgerEntry($wallet['wallet_id'], 10);

        $this->assertConstraintViolation(
            'wallet_balance_states_contiguous_version_check',
            fn () => DB::table('wallet_balance_states')->insert([
                'wallet_id' => $wallet['wallet_id'],
                'allows_negative' => false,
                'version' => 2,
                'previous_state_id' => $wallet['state_id'],
                'previous_version' => 0,
                'ledger_entry_id' => $entryId,
                'delta_minor' => 10,
                'balance_before_minor' => 0,
                'balance_after_minor' => 10,
                'created_at' => now(),
            ]),
        );
    }

    public function test_opening_balance_must_equal_the_predecessor_closing_balance(): void
    {
        $wallet = $this->insertWalletWithInitialState();
        $entryId = $this->insertLedgerEntry($wallet['wallet_id'], 10);

        $this->assertConstraintViolation(
            'wallet_balance_states_predecessor_fk',
            fn () => DB::table('wallet_balance_states')->insert([
                'wallet_id' => $wallet['wallet_id'],
                'allows_negative' => false,
                'version' => 1,
                'previous_state_id' => $wallet['state_id'],
                'previous_version' => 0,
                'ledger_entry_id' => $entryId,
                'delta_minor' => 10,
                'balance_before_minor' => 1,
                'balance_after_minor' => 11,
                'created_at' => now(),
            ]),
        );
    }

    public function test_state_delta_must_equal_its_linked_ledger_entry(): void
    {
        $wallet = $this->insertWalletWithInitialState();
        $entryId = $this->insertLedgerEntry($wallet['wallet_id'], 10);

        $this->assertConstraintViolation(
            'wallet_balance_states_entry_fk',
            fn () => DB::table('wallet_balance_states')->insert([
                'wallet_id' => $wallet['wallet_id'],
                'allows_negative' => false,
                'version' => 1,
                'previous_state_id' => $wallet['state_id'],
                'previous_version' => 0,
                'ledger_entry_id' => $entryId,
                'delta_minor' => 9,
                'balance_before_minor' => 0,
                'balance_after_minor' => 9,
                'created_at' => now(),
            ]),
        );
    }

    public function test_user_wallet_state_cannot_be_negative(): void
    {
        $wallet = $this->insertWalletWithInitialState();
        $entryId = $this->insertLedgerEntry($wallet['wallet_id'], -1);

        $this->assertConstraintViolation(
            'wallet_balance_states_nonnegative_check',
            fn () => DB::table('wallet_balance_states')->insert([
                'wallet_id' => $wallet['wallet_id'],
                'allows_negative' => false,
                'version' => 1,
                'previous_state_id' => $wallet['state_id'],
                'previous_version' => 0,
                'ledger_entry_id' => $entryId,
                'delta_minor' => -1,
                'balance_before_minor' => 0,
                'balance_after_minor' => -1,
                'created_at' => now(),
            ]),
        );
    }

    public function test_wallet_provisioning_creates_uuidv7_identity_and_version_zero_state(): void
    {
        $wallet = app(WalletProvisioner::class)->create('user', 'provisioned-owner');

        self::assertSame('7', $wallet->id[14]);
        self::assertFalse($wallet->allows_negative);
        self::assertDatabaseHas('wallet_balance_states', [
            'wallet_id' => $wallet->id,
            'allows_negative' => false,
            'version' => 0,
            'previous_state_id' => null,
            'previous_version' => null,
            'ledger_entry_id' => null,
            'delta_minor' => 0,
            'balance_before_minor' => 0,
            'balance_after_minor' => 0,
        ]);
    }

    #[DataProvider('immutableTableMutations')]
    public function test_financial_core_rows_reject_direct_update_and_delete(string $table, string $operation): void
    {
        $fixture = $this->insertCompleteFinancialFixture();
        $key = match ($table) {
            'wallets' => $fixture['wallet_id'],
            'ledger_transactions' => $fixture['transaction_id'],
            'ledger_entries' => $fixture['entry_id'],
            'wallet_balance_states' => $fixture['state_id'],
        };

        $sql = $operation === 'UPDATE'
            ? sprintf('UPDATE %s SET created_at = created_at WHERE id = ?', $table)
            : sprintf('DELETE FROM %s WHERE id = ?', $table);

        try {
            DB::statement($sql, [$key]);
            self::fail(sprintf('%s on %s unexpectedly succeeded.', $operation, $table));
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0] ?? null);
            self::assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function immutableTableMutations(): array
    {
        $cases = [];

        foreach (['wallets', 'ledger_transactions', 'ledger_entries', 'wallet_balance_states'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $cases[strtolower($operation).' '.$table] = [$table, $operation];
            }
        }

        return $cases;
    }

    /** @return array{wallet_id: string, state_id: int} */
    private function insertWalletWithInitialState(): array
    {
        $walletId = $this->insertWallet('user', 'owner-'.Str::uuid7());
        $stateId = DB::table('wallet_balance_states')->insertGetId([
            'wallet_id' => $walletId,
            'allows_negative' => false,
            'version' => 0,
            'previous_state_id' => null,
            'previous_version' => null,
            'ledger_entry_id' => null,
            'delta_minor' => 0,
            'balance_before_minor' => 0,
            'balance_after_minor' => 0,
            'created_at' => now(),
        ]);

        return ['wallet_id' => $walletId, 'state_id' => $stateId];
    }

    private function insertWallet(string $ownerType, string $ownerId, bool $allowsNegative = false): string
    {
        $walletId = (string) Str::uuid7();

        DB::table('wallets')->insert([
            'id' => $walletId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'allows_negative' => $allowsNegative,
            'created_at' => now(),
        ]);

        return $walletId;
    }

    private function insertLedgerEntry(string $walletId, int $amountMinor): int
    {
        $transactionId = (string) Str::uuid7();

        DB::table('ledger_transactions')->insert([
            'id' => $transactionId,
            'type' => 'deposit',
            'reference_type' => LedgerReferenceType::DEPOSIT->value,
            'reference_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);

        return DB::table('ledger_entries')->insertGetId([
            'ledger_transaction_id' => $transactionId,
            'wallet_id' => $walletId,
            'amount_minor' => $amountMinor,
            'created_at' => now(),
        ]);
    }

    /** @param array{wallet_id: string, state_id: int} $wallet */
    private function insertNextState(array $wallet, int $amountMinor): int
    {
        $entryId = $this->insertLedgerEntry($wallet['wallet_id'], $amountMinor);

        return DB::table('wallet_balance_states')->insertGetId([
            'wallet_id' => $wallet['wallet_id'],
            'allows_negative' => false,
            'version' => 1,
            'previous_state_id' => $wallet['state_id'],
            'previous_version' => 0,
            'ledger_entry_id' => $entryId,
            'delta_minor' => $amountMinor,
            'balance_before_minor' => 0,
            'balance_after_minor' => $amountMinor,
            'created_at' => now(),
        ]);
    }

    /** @return array{wallet_id: string, transaction_id: string, entry_id: int, state_id: int} */
    private function insertCompleteFinancialFixture(): array
    {
        $wallet = $this->insertWalletWithInitialState();
        $transactionId = (string) Str::uuid7();

        DB::table('ledger_transactions')->insert([
            'id' => $transactionId,
            'type' => 'deposit',
            'reference_type' => LedgerReferenceType::DEPOSIT->value,
            'reference_id' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);

        $entryId = DB::table('ledger_entries')->insertGetId([
            'ledger_transaction_id' => $transactionId,
            'wallet_id' => $wallet['wallet_id'],
            'amount_minor' => 1,
            'created_at' => now(),
        ]);

        $stateId = DB::table('wallet_balance_states')->insertGetId([
            'wallet_id' => $wallet['wallet_id'],
            'allows_negative' => false,
            'version' => 1,
            'previous_state_id' => $wallet['state_id'],
            'previous_version' => 0,
            'ledger_entry_id' => $entryId,
            'delta_minor' => 1,
            'balance_before_minor' => 0,
            'balance_after_minor' => 1,
            'created_at' => now(),
        ]);

        return [
            'wallet_id' => $wallet['wallet_id'],
            'transaction_id' => $transactionId,
            'entry_id' => $entryId,
            'state_id' => $stateId,
        ];
    }

    private function assertConstraintViolation(string $constraint, callable $operation): void
    {
        try {
            $operation();
            self::fail(sprintf('Expected constraint %s to reject the operation.', $constraint));
        } catch (QueryException $exception) {
            self::assertStringContainsString($constraint, $exception->getMessage());
        }
    }
}
