<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Domain\Wallet\PostingLine;
use App\Domain\Wallet\WithdrawalStatus;
use App\Exceptions\Wallet\WithdrawalConflict;
use App\Models\Withdrawal;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\OutboxRecorder;
use InvalidArgumentException;

final readonly class RejectWithdrawal
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private LedgerPostingService $ledger,
        private OutboxRecorder $outbox,
    ) {}

    public function handle(string $withdrawalId, string $idempotencyKey, string $reason): CommandResult
    {
        $withdrawal = Withdrawal::query()->findOrFail($withdrawalId);
        $reason = trim($reason);

        if ($reason === '' || strlen($reason) > 255) {
            throw new InvalidArgumentException('Rejection reason must contain between 1 and 255 characters.');
        }

        return $this->idempotency->execute(
            walletId: $withdrawal->wallet_id,
            operation: OperationType::REJECT_WITHDRAWAL,
            key: IdempotencyKey::fromString($idempotencyKey),
            normalizedRequest: ['withdrawal_id' => $withdrawalId, 'reason' => $reason],
            databaseOnlyOperation: function () use ($withdrawalId, $reason): CommandResult {
                /** @var Withdrawal $locked */
                $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawalId);

                if (! in_array($locked->status, [WithdrawalStatus::PENDING, WithdrawalStatus::APPROVED], true)) {
                    throw new WithdrawalConflict('Only a pending or approved withdrawal can be rejected.');
                }

                $compensationId = null;

                if ($locked->status === WithdrawalStatus::APPROVED) {
                    $posting = $this->ledger->post(new PostingCommand(
                        type: LedgerTransactionType::WITHDRAWAL,
                        referenceType: LedgerReferenceType::WITHDRAWAL_COMPENSATION,
                        referenceId: $locked->id,
                        posting: BalancedPosting::fromLines([
                            new PostingLine($locked->suspense_wallet_id, -$locked->amount_minor),
                            new PostingLine($locked->wallet_id, $locked->amount_minor),
                        ]),
                    ));
                    $compensationId = $posting->transactionId();
                }

                $locked->forceFill([
                    'status' => WithdrawalStatus::REJECTED,
                    'compensation_ledger_transaction_id' => $compensationId,
                    'rejection_reason' => $reason,
                    'rejected_at' => now(),
                ])->save();

                $this->outbox->record(
                    OutboxEventType::WITHDRAWAL_FAILED,
                    OutboxAggregateType::WITHDRAWAL,
                    $locked->id,
                    $locked->payload(),
                );

                return CommandResult::succeeded(['data' => $locked->payload()]);
            },
        );
    }
}
