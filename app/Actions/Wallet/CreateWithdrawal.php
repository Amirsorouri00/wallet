<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\Money;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Domain\Wallet\WithdrawalStatus;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\OutboxRecorder;
use App\Services\Wallet\WithdrawalSuspenseSelector;
use InvalidArgumentException;

final readonly class CreateWithdrawal
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private WithdrawalSuspenseSelector $suspenseWallets,
        private OutboxRecorder $outbox,
    ) {}

    public function handle(string $walletId, int $amountMinor, string $idempotencyKey): CommandResult
    {
        $amount = Money::fromMinor($amountMinor);
        $key = IdempotencyKey::fromString($idempotencyKey);
        $wallet = Wallet::query()->find($walletId);

        if ($wallet === null || $wallet->owner_type !== 'user') {
            throw new InvalidArgumentException('Withdrawals are allowed only for an existing user wallet.');
        }

        return $this->idempotency->execute(
            walletId: $walletId,
            operation: OperationType::REQUEST_WITHDRAWAL,
            key: $key,
            normalizedRequest: ['amount_minor' => $amount->minor()],
            databaseOnlyOperation: function (string $withdrawalId) use ($walletId, $amount): CommandResult {
                $suspense = $this->suspenseWallets->select($withdrawalId);
                $withdrawal = Withdrawal::query()->create([
                    'id' => $withdrawalId,
                    'wallet_id' => $walletId,
                    'suspense_wallet_id' => $suspense->id,
                    'amount_minor' => $amount->minor(),
                    'status' => WithdrawalStatus::PENDING,
                ]);

                $this->outbox->record(
                    eventType: OutboxEventType::WITHDRAWAL_PENDING,
                    aggregateType: OutboxAggregateType::WITHDRAWAL,
                    aggregateId: $withdrawal->id,
                    payload: $withdrawal->payload(),
                );

                return CommandResult::created(['data' => $withdrawal->payload()]);
            },
        );
    }
}
