<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\CommandError;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Domain\Wallet\PostingLine;
use App\Domain\Wallet\WithdrawalStatus;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Exceptions\Wallet\WithdrawalConflict;
use App\Models\Withdrawal;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\OutboxRecorder;

final readonly class ApproveWithdrawal
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private LedgerPostingService $ledger,
        private OutboxRecorder $outbox,
    ) {}

    public function handle(string $withdrawalId, string $idempotencyKey): CommandResult
    {
        $withdrawal = Withdrawal::query()->findOrFail($withdrawalId);

        return $this->idempotency->execute(
            walletId: $withdrawal->wallet_id,
            operation: OperationType::APPROVE_WITHDRAWAL,
            key: IdempotencyKey::fromString($idempotencyKey),
            normalizedRequest: ['withdrawal_id' => $withdrawalId],
            databaseOnlyOperation: function () use ($withdrawalId): CommandResult {
                /** @var Withdrawal $locked */
                $locked = Withdrawal::query()->lockForUpdate()->findOrFail($withdrawalId);

                if ($locked->status !== WithdrawalStatus::PENDING) {
                    throw new WithdrawalConflict('Only a pending withdrawal can be approved.');
                }

                try {
                    $posting = $this->ledger->post(new PostingCommand(
                        type: LedgerTransactionType::WITHDRAWAL,
                        referenceType: LedgerReferenceType::WITHDRAWAL_APPROVAL,
                        referenceId: $locked->id,
                        posting: BalancedPosting::fromLines([
                            new PostingLine($locked->wallet_id, -$locked->amount_minor),
                            new PostingLine($locked->suspense_wallet_id, $locked->amount_minor),
                        ]),
                    ));
                } catch (InsufficientFunds) {
                    return CommandResult::rejected(CommandError::INSUFFICIENT_FUNDS);
                }

                $locked->forceFill([
                    'status' => WithdrawalStatus::APPROVED,
                    'approval_ledger_transaction_id' => $posting->transactionId(),
                    'approved_at' => now(),
                ])->save();

                $this->outbox->record(
                    OutboxEventType::WITHDRAWAL_APPROVED,
                    OutboxAggregateType::WITHDRAWAL,
                    $locked->id,
                    $locked->payload(),
                );

                return CommandResult::succeeded(['data' => $locked->payload()]);
            },
        );
    }
}
