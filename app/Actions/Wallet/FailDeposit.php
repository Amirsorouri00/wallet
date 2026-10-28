<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Data\Wallet\GatewayCallback;
use App\Domain\Wallet\DepositStatus;
use App\Domain\Wallet\DepositTransition;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Exceptions\Wallet\CallbackConflict;
use App\Models\Deposit;
use App\Services\Wallet\OutboxRecorder;
use Illuminate\Support\Facades\DB;

final readonly class FailDeposit
{
    public function __construct(private OutboxRecorder $outbox) {}

    public function handle(string $depositId, GatewayCallback $callback): CommandResult
    {
        return DB::transaction(function () use ($depositId, $callback): CommandResult {
            /** @var Deposit $deposit */
            $deposit = Deposit::query()->lockForUpdate()->findOrFail($depositId);
            $this->assertCallbackMatches($deposit, $callback);
            $transition = $deposit->status->transitionTo(DepositStatus::FAILED);

            if ($transition === DepositTransition::CONFLICT) {
                throw new CallbackConflict('The deposit already has the opposite terminal status.');
            }

            if ($transition === DepositTransition::NOOP) {
                return $this->response($deposit);
            }

            $deposit->forceFill([
                'status' => DepositStatus::FAILED,
                'ledger_transaction_id' => null,
                'failure_reason' => 'GATEWAY_REJECTED',
                'resolved_at' => now(),
            ])->save();

            $this->outbox->record(
                eventType: OutboxEventType::DEPOSIT_FAILED,
                aggregateType: OutboxAggregateType::DEPOSIT,
                aggregateId: $deposit->id,
                payload: $this->payload($deposit),
            );

            return $this->response($deposit);
        }, attempts: (int) config('wallet.deadlock_retries'));
    }

    private function assertCallbackMatches(Deposit $deposit, GatewayCallback $callback): void
    {
        if (
            ! hash_equals($deposit->gateway_reference, $callback->gatewayReference())
            || $deposit->amount_minor !== $callback->amountMinor()
        ) {
            throw new CallbackConflict('Gateway callback reference or amount does not match the deposit.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(Deposit $deposit): array
    {
        return [
            'version' => 1,
            'deposit_id' => $deposit->id,
            'wallet_id' => $deposit->wallet_id,
            'clearing_wallet_id' => $deposit->clearing_wallet_id,
            'amount_minor' => $deposit->amount_minor,
            'status' => DepositStatus::FAILED->value,
            'gateway_reference' => $deposit->gateway_reference,
            'failure_reason' => $deposit->failure_reason,
        ];
    }

    private function response(Deposit $deposit): CommandResult
    {
        return CommandResult::succeeded(['data' => $this->payload($deposit)]);
    }
}
