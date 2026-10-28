<?php

namespace App\Services\Wallet;

use App\Data\Wallet\PostingCommand;
use App\Data\Wallet\PostingResult;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Exceptions\Wallet\InvalidPosting;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\WalletBalanceState;
use Illuminate\Support\Facades\DB;
use LogicException;

final class LedgerPostingService
{
    public function post(PostingCommand $command): PostingResult
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Ledger posting requires an active database transaction.');
        }

        $lines = $command->posting()->lines();
        $walletIds = array_map(
            static fn ($line): string => $line->walletId(),
            $lines,
        );
        $lockOrder = $walletIds;
        sort($lockOrder, SORT_STRING);

        $wallets = Wallet::query()
            ->whereIn('id', $lockOrder)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($wallets->count() !== count($lockOrder)) {
            throw new InvalidPosting('Every posting wallet must exist.');
        }

        /** @var array<string, WalletBalanceState> $latestStates */
        $latestStates = [];
        /** @var array<string, int> $balancesAfter */
        $balancesAfter = [];

        foreach ($lines as $line) {
            /** @var Wallet $wallet */
            $wallet = $wallets->get($line->walletId());
            /** @var WalletBalanceState|null $latestState */
            $latestState = WalletBalanceState::query()
                ->where('wallet_id', $wallet->id)
                ->orderByDesc('version')
                ->first();

            if ($latestState === null) {
                throw new InvalidPosting('Every posting wallet must have an initial balance state.');
            }

            $balanceAfter = WalletBalanceState::calculateBalanceAfter(
                $latestState->balance_after_minor,
                $line->amountMinor(),
            );

            if (! $wallet->allows_negative && $balanceAfter < 0) {
                throw new InsufficientFunds(sprintf(
                    'Insufficient balance for wallet %s.',
                    $wallet->id,
                ));
            }

            $latestStates[$wallet->id] = $latestState;
            $balancesAfter[$wallet->id] = $balanceAfter;
        }

        $transaction = LedgerTransaction::query()->create([
            'type' => $command->type(),
            'reference_type' => $command->referenceType(),
            'reference_id' => $command->referenceId(),
        ]);

        /** @var array<string, int> $entryIdsByWallet */
        $entryIdsByWallet = [];

        foreach ($lines as $line) {
            $entry = LedgerEntry::query()->create([
                'ledger_transaction_id' => $transaction->id,
                'wallet_id' => $line->walletId(),
                'amount_minor' => $line->amountMinor(),
            ]);
            $entryIdsByWallet[$line->walletId()] = $entry->id;
        }

        /** @var array<string, int> $stateIdsByWallet */
        $stateIdsByWallet = [];

        foreach ($lines as $line) {
            $walletId = $line->walletId();
            $previous = $latestStates[$walletId];
            /** @var Wallet $wallet */
            $wallet = $wallets->get($walletId);
            $state = WalletBalanceState::query()->create([
                'wallet_id' => $walletId,
                'allows_negative' => $wallet->allows_negative,
                'version' => $previous->version + 1,
                'previous_state_id' => $previous->id,
                'previous_version' => $previous->version,
                'ledger_entry_id' => $entryIdsByWallet[$walletId],
                'delta_minor' => $line->amountMinor(),
                'balance_before_minor' => $previous->balance_after_minor,
                'balance_after_minor' => $balancesAfter[$walletId],
            ]);
            $stateIdsByWallet[$walletId] = $state->id;
        }

        return new PostingResult(
            transactionId: $transaction->id,
            entryIdsByWallet: $entryIdsByWallet,
            stateIdsByWallet: $stateIdsByWallet,
        );
    }
}
