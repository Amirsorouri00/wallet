<?php

namespace Tests\Unit\Wallet;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\OperationType;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

class IdempotencyFingerprintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PostgresTestDatabase::assertPostgres();
    }

    public function test_associative_key_order_does_not_change_sha256_fingerprint(): void
    {
        $left = IdempotencyCoordinator::fingerprint([
            'destination_wallet_id' => 'wallet-b',
            'amount_minor' => 80,
            'metadata' => ['z' => 2, 'a' => 1],
        ]);
        $right = IdempotencyCoordinator::fingerprint([
            'metadata' => ['a' => 1, 'z' => 2],
            'amount_minor' => 80,
            'destination_wallet_id' => 'wallet-b',
        ]);

        self::assertSame($left, $right);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $left);
        self::assertNotSame($left, IdempotencyCoordinator::fingerprint([
            'destination_wallet_id' => 'wallet-b',
            'amount_minor' => 81,
            'metadata' => ['a' => 1, 'z' => 2],
        ]));
    }

    public function test_committed_response_replays_without_executing_callback_again(): void
    {
        $walletId = app(WalletProvisioner::class)->create('user', 'idempotency-user')->id;
        $coordinator = app(IdempotencyCoordinator::class);
        $executions = 0;
        $operation = function (string $operationId) use (&$executions): CommandResult {
            $executions++;

            return CommandResult::succeeded([
                'data' => ['operation_id' => $operationId, 'amount_minor' => 80],
            ]);
        };

        $first = $coordinator->execute(
            $walletId,
            OperationType::TRANSFER,
            IdempotencyKey::fromString('same-request'),
            ['destination_wallet_id' => 'wallet-b', 'amount_minor' => 80],
            $operation,
        );
        $replayed = $coordinator->execute(
            $walletId,
            OperationType::TRANSFER,
            IdempotencyKey::fromString('same-request'),
            ['amount_minor' => 80, 'destination_wallet_id' => 'wallet-b'],
            $operation,
        );

        self::assertSame(1, $executions);
        self::assertSame($first->outcome(), $replayed->outcome());
        self::assertSame($first->body(), $replayed->body());
        self::assertSame($first->successful(), $replayed->successful());
        self::assertSame(1, DB::table('idempotency_records')->count());
    }

    public function test_same_key_with_changed_request_conflicts(): void
    {
        $walletId = app(WalletProvisioner::class)->create('user', 'conflict-user')->id;
        $coordinator = app(IdempotencyCoordinator::class);
        $operation = fn (string $operationId): CommandResult => CommandResult::succeeded(
            ['data' => ['operation_id' => $operationId]],
        );

        $coordinator->execute(
            $walletId,
            OperationType::TRANSFER,
            IdempotencyKey::fromString('changed-request'),
            ['destination_wallet_id' => 'wallet-b', 'amount_minor' => 80],
            $operation,
        );

        $this->expectException(IdempotencyConflict::class);

        $coordinator->execute(
            $walletId,
            OperationType::TRANSFER,
            IdempotencyKey::fromString('changed-request'),
            ['destination_wallet_id' => 'wallet-c', 'amount_minor' => 80],
            $operation,
        );
    }

    public function test_transient_failure_rolls_back_key_reservation(): void
    {
        $walletId = app(WalletProvisioner::class)->create('user', 'rollback-user')->id;
        $coordinator = app(IdempotencyCoordinator::class);

        try {
            $coordinator->execute(
                $walletId,
                OperationType::TRANSFER,
                IdempotencyKey::fromString('retry-after-failure'),
                ['destination_wallet_id' => 'wallet-b', 'amount_minor' => 80],
                fn (string $operationId) => throw new RuntimeException($operationId),
            );
            self::fail('A transient callback failure unexpectedly returned.');
        } catch (RuntimeException) {
            self::assertSame(0, DB::table('idempotency_records')->count());
        }
    }
}
