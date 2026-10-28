<?php

namespace Tests\Integration\Wallet;

use App\Services\Wallet\WalletProvisioner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class OperationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    #[DataProvider('invalidDepositStates')]
    public function test_deposit_state_columns_must_match_status(
        string $status,
        bool $withLedger,
        ?string $failureReason,
        bool $withResolvedAt,
    ): void {
        $depositId = (string) Str::uuid7();
        $wallets = $this->operationWallets();
        $ledgerTransactionId = $withLedger ? $this->insertLedgerTransaction($depositId) : null;

        $this->assertConstraintViolation(
            'deposits_status_fields_check',
            fn () => DB::table('deposits')->insert($this->depositAttributes(
                id: $depositId,
                walletId: $wallets['user'],
                clearingWalletId: $wallets['clearing'],
                status: $status,
                ledgerTransactionId: $ledgerTransactionId,
                failureReason: $failureReason,
                resolvedAt: $withResolvedAt ? now() : null,
            )),
        );
    }

    /** @return array<string, array{string, bool, ?string, bool}> */
    public static function invalidDepositStates(): array
    {
        return [
            'pending with ledger' => ['PENDING', true, null, false],
            'pending with failure' => ['PENDING', false, 'DECLINED', false],
            'pending with resolution' => ['PENDING', false, null, true],
            'succeeded without ledger' => ['SUCCEEDED', false, null, true],
            'succeeded without resolution' => ['SUCCEEDED', true, null, false],
            'succeeded with failure' => ['SUCCEEDED', true, 'DECLINED', true],
            'failed with ledger' => ['FAILED', true, 'DECLINED', true],
            'failed without reason' => ['FAILED', false, null, true],
            'failed without resolution' => ['FAILED', false, 'DECLINED', false],
        ];
    }

    #[DataProvider('nonPositiveAmounts')]
    public function test_deposit_amount_must_be_positive(int $amountMinor): void
    {
        $wallets = $this->operationWallets();

        $this->assertConstraintViolation(
            'deposits_amount_positive_check',
            fn () => DB::table('deposits')->insert($this->depositAttributes(
                walletId: $wallets['user'],
                clearingWalletId: $wallets['clearing'],
                amountMinor: $amountMinor,
            )),
        );
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    public function test_gateway_reference_is_unique(): void
    {
        $wallets = $this->operationWallets();
        $gatewayReference = (string) Str::uuid7();
        DB::table('deposits')->insert($this->depositAttributes(
            walletId: $wallets['user'],
            clearingWalletId: $wallets['clearing'],
            gatewayReference: $gatewayReference,
        ));

        $this->assertConstraintViolation(
            'deposits_gateway_reference_unique',
            fn () => DB::table('deposits')->insert($this->depositAttributes(
                walletId: $wallets['user'],
                clearingWalletId: $wallets['clearing'],
                gatewayReference: $gatewayReference,
            )),
        );
    }

    public function test_successful_deposit_ledger_link_is_unique(): void
    {
        $wallets = $this->operationWallets();
        $firstDepositId = (string) Str::uuid7();
        $ledgerTransactionId = $this->insertLedgerTransaction($firstDepositId);
        DB::table('deposits')->insert($this->depositAttributes(
            id: $firstDepositId,
            walletId: $wallets['user'],
            clearingWalletId: $wallets['clearing'],
            status: 'SUCCEEDED',
            ledgerTransactionId: $ledgerTransactionId,
            resolvedAt: now(),
        ));

        $this->assertConstraintViolation(
            'deposits_ledger_transaction_unique',
            fn () => DB::table('deposits')->insert($this->depositAttributes(
                walletId: $wallets['user'],
                clearingWalletId: $wallets['clearing'],
                status: 'SUCCEEDED',
                ledgerTransactionId: $ledgerTransactionId,
                resolvedAt: now(),
            )),
        );
    }

    public function test_wallet_roles_are_enforced_for_deposits(): void
    {
        $provisioner = app(WalletProvisioner::class);
        $user = $provisioner->create('user', 'deposit-user');
        $otherUser = $provisioner->create('user', 'not-a-clearing-wallet');

        $this->assertConstraintViolation(
            'deposits_clearing_wallet_role_check',
            fn () => DB::table('deposits')->insert($this->depositAttributes(
                walletId: $user->id,
                clearingWalletId: $otherUser->id,
            )),
        );
    }

    public function test_selected_clearing_wallet_is_immutable(): void
    {
        $wallets = $this->operationWallets();
        $depositId = (string) Str::uuid7();
        DB::table('deposits')->insert($this->depositAttributes(
            id: $depositId,
            walletId: $wallets['user'],
            clearingWalletId: $wallets['clearing'],
        ));

        try {
            DB::table('deposits')->where('id', $depositId)->update([
                'clearing_wallet_id' => $wallets['user'],
            ]);
            self::fail('Changing a deposit clearing wallet unexpectedly succeeded.');
        } catch (QueryException $exception) {
            self::assertSame('55000', $exception->errorInfo[0] ?? null);
            self::assertStringContainsString('deposits_clearing_wallet_immutable', $exception->getMessage());
        }
    }

    public function test_idempotency_key_is_unique_within_actor_and_operation_scope(): void
    {
        $wallets = $this->operationWallets();
        $attributes = $this->idempotencyAttributes($wallets['user']);
        DB::table('idempotency_records')->insert($attributes);

        $this->assertConstraintViolation(
            'idempotency_records_scope_unique',
            fn () => DB::table('idempotency_records')->insert([
                ...$attributes,
                'id' => (string) Str::uuid7(),
            ]),
        );
    }

    #[DataProvider('inconsistentIdempotencyResults')]
    public function test_idempotency_terminal_response_fields_are_consistent(
        string $resultStatus,
        ?int $httpStatus,
        ?array $responseBody,
    ): void {
        $wallets = $this->operationWallets();

        $this->assertConstraintViolation(
            'idempotency_records_result_fields_check',
            fn () => DB::table('idempotency_records')->insert($this->idempotencyAttributes(
                actorWalletId: $wallets['user'],
                resultStatus: $resultStatus,
                httpStatus: $httpStatus,
                responseBody: $responseBody,
            )),
        );
    }

    /** @return array<string, array{string, ?int, ?array<string, mixed>}> */
    public static function inconsistentIdempotencyResults(): array
    {
        return [
            'processing with status' => ['processing', 202, null],
            'processing with body' => ['processing', null, ['pending' => true]],
            'succeeded without status' => ['succeeded', null, ['ok' => true]],
            'succeeded without body' => ['succeeded', 201, null],
            'rejected without status' => ['rejected', null, ['error' => 'rejected']],
            'rejected without body' => ['rejected', 422, null],
        ];
    }

    public function test_outbox_attempts_cannot_be_negative(): void
    {
        $this->assertConstraintViolation(
            'outbox_messages_attempts_nonnegative_check',
            fn () => DB::table('outbox_messages')->insert($this->outboxAttributes(attempts: -1)),
        );
    }

    #[DataProvider('inconsistentLeases')]
    public function test_outbox_lease_fields_are_consistent(?string $owner, bool $withExpiry): void
    {
        $this->assertConstraintViolation(
            'outbox_messages_lease_fields_check',
            fn () => DB::table('outbox_messages')->insert($this->outboxAttributes(
                leaseOwner: $owner,
                leasedUntil: $withExpiry ? now()->addMinute() : null,
            )),
        );
    }

    /** @return array<string, array{?string, bool}> */
    public static function inconsistentLeases(): array
    {
        return [
            'owner without expiry' => ['worker-1', false],
            'expiry without owner' => [null, true],
        ];
    }

    public function test_outbox_has_unpublished_availability_partial_index(): void
    {
        $definition = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'outbox_messages')
            ->where('indexname', 'outbox_messages_unpublished_available_index')
            ->value('indexdef');

        self::assertIsString($definition);
        self::assertStringContainsString('(available_at, occurred_at, id)', $definition);
        self::assertStringContainsString('published_at IS NULL', $definition);
        self::assertStringContainsString('dead_lettered_at IS NULL', $definition);
    }

    public function test_database_seeder_is_repeatable_and_creates_exactly_82_initial_states(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        self::assertSame(82, DB::table('wallets')->count());
        self::assertSame(82, DB::table('wallet_balance_states')->count());
        self::assertSame(80, DB::table('wallets')->where('owner_type', 'system')->count());
        self::assertSame(2, DB::table('wallets')->where('owner_type', 'user')->count());
    }

    /** @return array{user: string, clearing: string} */
    private function operationWallets(): array
    {
        $provisioner = app(WalletProvisioner::class);
        $user = $provisioner->create('user', 'operation-user');
        $clearing = $provisioner->create('system', 'gateway-clearing-00', true);

        return ['user' => $user->id, 'clearing' => $clearing->id];
    }

    private function insertLedgerTransaction(string $referenceId): string
    {
        $id = (string) Str::uuid7();
        DB::table('ledger_transactions')->insert([
            'id' => $id,
            'type' => 'deposit',
            'reference_type' => 'deposit',
            'reference_id' => $referenceId,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function depositAttributes(
        ?string $id = null,
        string $walletId = '',
        string $clearingWalletId = '',
        int $amountMinor = 100,
        string $status = 'PENDING',
        ?string $gatewayReference = null,
        ?string $ledgerTransactionId = null,
        ?string $failureReason = null,
        mixed $resolvedAt = null,
    ): array {
        return [
            'id' => $id ?? (string) Str::uuid7(),
            'wallet_id' => $walletId,
            'clearing_wallet_id' => $clearingWalletId,
            'amount_minor' => $amountMinor,
            'status' => $status,
            'gateway_reference' => $gatewayReference ?? (string) Str::uuid7(),
            'ledger_transaction_id' => $ledgerTransactionId,
            'failure_reason' => $failureReason,
            'resolved_at' => $resolvedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $responseBody
     * @return array<string, mixed>
     */
    private function idempotencyAttributes(
        string $actorWalletId,
        string $resultStatus = 'processing',
        ?int $httpStatus = null,
        ?array $responseBody = null,
    ): array {
        return [
            'id' => (string) Str::uuid7(),
            'actor_wallet_id' => $actorWalletId,
            'operation' => 'create_deposit',
            'idempotency_key' => 'test-key',
            'request_hash' => hash('sha256', 'request'),
            'result_status' => $resultStatus,
            'http_status' => $httpStatus,
            'response_body' => $responseBody === null ? null : json_encode($responseBody, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function outboxAttributes(
        int $attempts = 0,
        ?string $leaseOwner = null,
        mixed $leasedUntil = null,
    ): array {
        return [
            'id' => (string) Str::uuid7(),
            'event_type' => 'DepositPending',
            'aggregate_type' => 'deposit',
            'aggregate_id' => (string) Str::uuid7(),
            'payload' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'published_at' => null,
            'attempts' => $attempts,
            'available_at' => now(),
            'lease_owner' => $leaseOwner,
            'leased_until' => $leasedUntil,
            'last_error' => null,
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
