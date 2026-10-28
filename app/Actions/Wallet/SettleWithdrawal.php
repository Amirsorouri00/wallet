<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Domain\Wallet\WithdrawalStatus;
use App\Exceptions\Wallet\WithdrawalConflict;
use App\Models\Withdrawal;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\OutboxRecorder;

final readonly class SettleWithdrawal
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private OutboxRecorder $outbox,
    ) {}

    public function handle(string $withdrawalId, string $idempotencyKey): CommandResult
    {
        $withdrawal = Withdrawal::query()->findOrFail($withdrawalId);

        return $this->idempotency->execute(
            walletId: $withdrawal->wallet_id,
            operation: OperationType::SETTLE_WITHDRAWAL,
            key: IdempotencyKey::fromString($idempotencyKey),
            normalizedRequest: ['withdrawal_id' => $withdrawalId],
            databaseOnlyOperation: function () use ($withdrawalId): CommandResult {
                /** @var Withdrawal $locked */
                $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawalId);

                if ($locked->status !== WithdrawalStatus::APPROVED) {
                    throw new WithdrawalConflict('Only an approved withdrawal can be settled.');
                }

                $locked->forceFill([
                    'status' => WithdrawalStatus::SETTLED,
                    'settled_at' => now(),
                ])->save();

                $this->outbox->record(
                    OutboxEventType::WITHDRAWAL_SUCCEEDED,
                    OutboxAggregateType::WITHDRAWAL,
                    $locked->id,
                    $locked->payload(),
                );

                return CommandResult::succeeded(['data' => $locked->payload()]);
            },
        );
    }
}
