<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileWallets extends Command
{
    protected $signature = 'wallets:reconcile
        {--wallet= : Limit checks to one wallet UUID}
        {--since= : Limit checks to rows affected at or after this timestamp}
        {--limit=100 : Maximum discrepancy rows to materialize}
        {--fail-on-drift : Return a failing exit code when drift is found}';

    protected $description = 'Read-only reconciliation of the authoritative ledger and wallet state projection';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $wallet = $this->option('wallet');
        $walletId = is_string($wallet) && $wallet !== '' ? $wallet : null;

        if ($limit === false || $limit < 1 || $limit > 10_000) {
            $this->error('The --limit option must be an integer between 1 and 10000.');

            return self::INVALID;
        }

        if ($walletId !== null && ! Str::isUuid($walletId)) {
            $this->error('The --wallet option must be a UUID.');

            return self::INVALID;
        }

        try {
            $since = is_string($this->option('since')) && $this->option('since') !== ''
                ? (new DateTimeImmutable($this->option('since')))->format(DATE_ATOM)
                : null;
        } catch (Exception) {
            $this->error('The --since option must be a valid timestamp.');

            return self::INVALID;
        }

        $discrepancies = [];
        foreach (['unbalancedTransactions', 'balanceMismatches', 'missingStateProjections', 'brokenStateChains'] as $check) {
            $remaining = $limit - count($discrepancies);

            if ($remaining === 0) {
                break;
            }

            array_push($discrepancies, ...$this->{$check}($walletId, $since, $remaining));
        }

        if ($discrepancies === []) {
            $this->info('No ledger or wallet-state drift detected.');

            return self::SUCCESS;
        }

        $this->table(
            ['type', 'identifier', 'detail'],
            array_map(static fn (array $row): array => [
                $row['type'],
                $row['identifier'],
                $row['detail'],
            ], $discrepancies),
        );
        $message = sprintf(
            'Reconciliation found %d discrepancy record(s); output is bounded by --limit=%d.',
            count($discrepancies),
            $limit,
        );

        if (! $this->option('fail-on-drift')) {
            $this->warn($message);

            return self::SUCCESS;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /** @return list<array{type: string, identifier: string, detail: string}> */
    private function unbalancedTransactions(?string $walletId, ?string $since, int $limit): array
    {
        return array_map(static fn (object $row): array => [
            'type' => 'UNBALANCED_TRANSACTION',
            'identifier' => $row->id,
            'detail' => sprintf('entry_count=%d sum_minor=%s', $row->entry_count, $row->sum_minor),
        ], DB::select(<<<'SQL'
            SELECT transaction.id::text AS id,
                   COUNT(entry.id)::integer AS entry_count,
                   COALESCE(SUM(entry.amount_minor), 0)::text AS sum_minor
            FROM ledger_transactions AS transaction
            LEFT JOIN ledger_entries AS entry ON entry.ledger_transaction_id = transaction.id
            WHERE (CAST(? AS uuid) IS NULL OR EXISTS (
                      SELECT 1 FROM ledger_entries AS scoped_entry
                      WHERE scoped_entry.ledger_transaction_id = transaction.id
                        AND scoped_entry.wallet_id = CAST(? AS uuid)
                  ))
              AND (CAST(? AS timestamptz) IS NULL OR transaction.created_at >= CAST(? AS timestamptz))
            GROUP BY transaction.id
            HAVING COUNT(entry.id) < 2 OR COALESCE(SUM(entry.amount_minor), 0) <> 0
            ORDER BY transaction.id
            LIMIT ?
            SQL, [$walletId, $walletId, $since, $since, $limit]));
    }

    /** @return list<array{type: string, identifier: string, detail: string}> */
    private function balanceMismatches(?string $walletId, ?string $since, int $limit): array
    {
        return array_map(static fn (object $row): array => [
            'type' => 'BALANCE_MISMATCH',
            'identifier' => $row->wallet_id,
            'detail' => sprintf('ledger_sum_minor=%s latest_state_minor=%s', $row->ledger_sum, $row->state_balance ?? 'missing'),
        ], DB::select(<<<'SQL'
            WITH ledger_totals AS (
                SELECT wallet_id, SUM(amount_minor) AS balance
                FROM ledger_entries
                GROUP BY wallet_id
            ), latest_states AS (
                SELECT DISTINCT ON (wallet_id) wallet_id, balance_after_minor
                FROM wallet_balance_states
                ORDER BY wallet_id, version DESC
            )
            SELECT wallet.id::text AS wallet_id,
                   COALESCE(ledger.balance, 0)::text AS ledger_sum,
                   state.balance_after_minor::text AS state_balance
            FROM wallets AS wallet
            LEFT JOIN ledger_totals AS ledger ON ledger.wallet_id = wallet.id
            LEFT JOIN latest_states AS state ON state.wallet_id = wallet.id
            WHERE (state.wallet_id IS NULL
               OR state.balance_after_minor <> COALESCE(ledger.balance, 0))
              AND (CAST(? AS uuid) IS NULL OR wallet.id = CAST(? AS uuid))
              AND (CAST(? AS timestamptz) IS NULL OR EXISTS (
                    SELECT 1 FROM ledger_entries AS recent_entry
                    WHERE recent_entry.wallet_id = wallet.id
                      AND recent_entry.created_at >= CAST(? AS timestamptz)
                  ))
            ORDER BY wallet.id
            LIMIT ?
            SQL, [$walletId, $walletId, $since, $since, $limit]));
    }

    /** @return list<array{type: string, identifier: string, detail: string}> */
    private function missingStateProjections(?string $walletId, ?string $since, int $limit): array
    {
        return array_map(static fn (object $row): array => [
            'type' => 'MISSING_STATE_PROJECTION',
            'identifier' => $row->entry_id,
            'detail' => sprintf('wallet_id=%s transaction_id=%s', $row->wallet_id, $row->transaction_id),
        ], DB::select(<<<'SQL'
            SELECT entry.id::text AS entry_id,
                   entry.wallet_id::text AS wallet_id,
                   entry.ledger_transaction_id::text AS transaction_id
            FROM ledger_entries AS entry
            LEFT JOIN wallet_balance_states AS state
              ON state.ledger_entry_id = entry.id
             AND state.wallet_id = entry.wallet_id
             AND state.delta_minor = entry.amount_minor
            WHERE state.id IS NULL
              AND (CAST(? AS uuid) IS NULL OR entry.wallet_id = CAST(? AS uuid))
              AND (CAST(? AS timestamptz) IS NULL OR entry.created_at >= CAST(? AS timestamptz))
            ORDER BY entry.id
            LIMIT ?
            SQL, [$walletId, $walletId, $since, $since, $limit]));
    }

    /** @return list<array{type: string, identifier: string, detail: string}> */
    private function brokenStateChains(?string $walletId, ?string $since, int $limit): array
    {
        return array_map(static fn (object $row): array => [
            'type' => 'BROKEN_STATE_CHAIN',
            'identifier' => $row->state_id,
            'detail' => sprintf('wallet_id=%s version=%s', $row->wallet_id, $row->version),
        ], DB::select(<<<'SQL'
            SELECT state.id::text AS state_id,
                   state.wallet_id::text AS wallet_id,
                   state.version::text AS version
            FROM wallet_balance_states AS state
            LEFT JOIN wallet_balance_states AS predecessor
              ON predecessor.id = state.previous_state_id
             AND predecessor.wallet_id = state.wallet_id
             AND predecessor.version = state.version - 1
             AND predecessor.balance_after_minor = state.balance_before_minor
            WHERE ((state.version = 0 AND (
                       state.previous_state_id IS NOT NULL
                    OR state.previous_version IS NOT NULL
                    OR state.balance_before_minor <> 0
                    OR state.balance_after_minor <> 0
                  ))
               OR (state.version > 0 AND predecessor.id IS NULL))
              AND (CAST(? AS uuid) IS NULL OR state.wallet_id = CAST(? AS uuid))
              AND (CAST(? AS timestamptz) IS NULL OR state.created_at >= CAST(? AS timestamptz))
            ORDER BY state.wallet_id, state.version
            LIMIT ?
            SQL, [$walletId, $walletId, $since, $since, $limit]));
    }
}
