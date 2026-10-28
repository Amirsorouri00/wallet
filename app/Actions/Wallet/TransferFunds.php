<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\CommandResult;
use App\Data\Wallet\PostingCommand;
use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\CommandError;
use App\Domain\Wallet\IdempotencyKey;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;
use App\Domain\Wallet\Money;
use App\Domain\Wallet\OperationType;
use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Domain\Wallet\PostingLine;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Models\Wallet;
use App\Services\Wallet\IdempotencyCoordinator;
use App\Services\Wallet\LedgerPostingService;
use App\Services\Wallet\OutboxRecorder;
use InvalidArgumentException;

final readonly class TransferFunds
{
    public function __construct(
        private IdempotencyCoordinator $idempotency,
        private LedgerPostingService $ledger,
        private OutboxRecorder $outbox,
    ) {}

    public function handle(
        string $sourceWalletId,
        string $destinationWalletId,
        int $amountMinor,
        string $idempotencyKey,
    ): CommandResult {
        $amount = Money::fromMinor($amountMinor);
        $key = IdempotencyKey::fromString($idempotencyKey);

        if ($sourceWalletId === $destinationWalletId) {
            throw new InvalidArgumentException('Source and destination wallets must differ.');
        }

        $wallets = Wallet::query()
            ->whereIn('id', [$sourceWalletId, $destinationWalletId])
            ->get()
            ->keyBy('id');
        $source = $wallets->get($sourceWalletId);
        $destination = $wallets->get($destinationWalletId);

        if ($source === null || $destination === null) {
            throw new InvalidArgumentException('Source and destination wallets must exist.');
        }

        if ($source->owner_type !== 'user' || $destination->owner_type !== 'user') {
            throw new InvalidArgumentException('Transfers are allowed only between user wallets.');
        }

        return $this->idempotency->execute(
            walletId: $sourceWalletId,
            operation: OperationType::TRANSFER,
            key: $key,
            normalizedRequest: [
                'destination_wallet_id' => $destinationWalletId,
                'amount_minor' => $amount->minor(),
            ],
            databaseOnlyOperation: function (string $operationId) use (
                $sourceWalletId,
                $destinationWalletId,
                $amount,
            ): CommandResult {
                try {
                    $posting = $this->ledger->post(new PostingCommand(
                        type: LedgerTransactionType::TRANSFER,
                        referenceType: LedgerReferenceType::TRANSFER,
                        referenceId: $operationId,
                        posting: BalancedPosting::fromLines([
                            new PostingLine($sourceWalletId, -$amount->minor()),
                            new PostingLine($destinationWalletId, $amount->minor()),
                        ]),
                    ));
                } catch (InsufficientFunds) {
                    return CommandResult::rejected(CommandError::INSUFFICIENT_FUNDS);
                }

                $payload = [
                    'version' => 1,
                    'transfer_id' => $operationId,
                    'ledger_transaction_id' => $posting->transactionId(),
                    'source_wallet_id' => $sourceWalletId,
                    'destination_wallet_id' => $destinationWalletId,
                    'amount_minor' => $amount->minor(),
                ];

                $this->outbox->record(
                    eventType: OutboxEventType::TRANSFER_COMPLETED,
                    aggregateType: OutboxAggregateType::TRANSFER,
                    aggregateId: $operationId,
                    payload: $payload,
                );

                return CommandResult::succeeded(['data' => $payload]);
            },
        );
    }
}
