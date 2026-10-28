<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Domain\Wallet\DepositStatus;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\Money;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Wallet\ClearingBucketSelector;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\OutboxRecorder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class CreateDeposit
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private OutboxRecorder $outbox,
        private ClearingBucketSelector $clearingBuckets,
    ) {}

    public function handle(
        string $walletId,
        int $amountMinor,
        string $idempotencyKey,
    ): CommandResult {
        $amount = Money::fromMinor($amountMinor);
        $key = IdempotencyKey::fromString($idempotencyKey);

        $wallet = Wallet::query()->find($walletId);

        if ($wallet === null) {
            throw new InvalidArgumentException('The deposit wallet must exist.');
        }

        if ($wallet->owner_type !== 'user') {
            throw new InvalidArgumentException('Deposits are allowed only for user wallets.');
        }

        return $this->idempotency->execute(
            walletId: $walletId,
            operation: OperationType::CREATE_DEPOSIT,
            key: $key,
            normalizedRequest: ['amount_minor' => $amount->minor()],
            databaseOnlyOperation: function (string $depositId) use ($walletId, $amount): CommandResult {
                $bucket = $this->clearingBuckets->bucketFor($depositId);
                $clearingWallet = Wallet::query()
                    ->where('owner_type', 'system')
                    ->where('owner_id', sprintf('gateway-clearing-%02d', $bucket))
                    ->first();

                if ($clearingWallet === null) {
                    throw new RuntimeException('The selected clearing wallet is not provisioned.');
                }

                $gatewayReference = (string) Str::uuid7();
                Deposit::query()->create([
                    'id' => $depositId,
                    'wallet_id' => $walletId,
                    'clearing_wallet_id' => $clearingWallet->id,
                    'amount_minor' => $amount->minor(),
                    'status' => DepositStatus::PENDING,
                    'gateway_reference' => $gatewayReference,
                ]);

                $payload = [
                    'version' => 1,
                    'deposit_id' => $depositId,
                    'wallet_id' => $walletId,
                    'clearing_wallet_id' => $clearingWallet->id,
                    'amount_minor' => $amount->minor(),
                    'status' => DepositStatus::PENDING->value,
                    'gateway_reference' => $gatewayReference,
                ];

                $this->outbox->record(
                    eventType: OutboxEventType::DEPOSIT_PENDING,
                    aggregateType: OutboxAggregateType::DEPOSIT,
                    aggregateId: $depositId,
                    payload: $payload,
                );

                return CommandResult::created(['data' => $payload]);
            },
        );
    }
}
