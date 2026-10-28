<?php

namespace App\Actions\Wallet;

use App\Data\Wallet\HistoryCursorPage;
use App\Data\Wallet\HistoryFilters;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ListWalletHistory
{
    public function handle(
        string $walletId,
        HistoryFilters $filters,
        ?string $cursor,
        int $limit,
    ): HistoryCursorPage {
        $perPage = min(max($limit, 1), (int) config('wallet.history_page_size_max'));
        $lastVersion = $cursor === null
            ? null
            : $this->decodeCursor($cursor, $walletId, $filters);

        $query = DB::table('wallet_balance_states as state')
            ->join('ledger_entries as entry', 'entry.id', '=', 'state.ledger_entry_id')
            ->join('ledger_transactions as transaction', 'transaction.id', '=', 'entry.ledger_transaction_id')
            ->where('state.wallet_id', $walletId)
            ->where('state.version', '>', 0)
            ->select([
                'state.version',
                'state.delta_minor',
                'state.balance_after_minor',
                'state.created_at',
                'transaction.id as transaction_id',
                'transaction.type',
                'transaction.reference_type',
                'transaction.reference_id',
            ])
            ->orderByDesc('state.version')
            ->limit($perPage + 1);

        if ($lastVersion !== null) {
            $query->where('state.version', '<', $lastVersion);
        }

        if ($filters->type() !== null) {
            $query->where('transaction.type', $filters->type());
        }

        if ($filters->direction() === 'credit') {
            $query->where('state.delta_minor', '>', 0);
        } elseif ($filters->direction() === 'debit') {
            $query->where('state.delta_minor', '<', 0);
        }

        if ($filters->from() !== null) {
            $query->where('state.created_at', '>=', $filters->from());
        }

        if ($filters->to() !== null) {
            $query->where('state.created_at', '<', $filters->to());
        }

        $rows = $query->get();
        $hasMore = $rows->count() > $perPage;
        $items = $rows->take($perPage)->map(static fn (object $row): array => [
            'transaction_id' => $row->transaction_id,
            'type' => $row->type,
            'reference_type' => $row->reference_type,
            'reference_id' => $row->reference_id,
            'version' => $row->version,
            'direction' => $row->delta_minor > 0 ? 'credit' : 'debit',
            'delta_minor' => $row->delta_minor,
            'balance_after_minor' => $row->balance_after_minor,
            'created_at' => (new \DateTimeImmutable($row->created_at))->format('Y-m-d\TH:i:s.uP'),
        ])->values()->all();
        $nextCursor = $hasMore
            ? $this->encodeCursor($walletId, $filters, $items[array_key_last($items)]['version'])
            : null;

        return new HistoryCursorPage($items, $nextCursor, $perPage);
    }

    private function encodeCursor(string $walletId, HistoryFilters $filters, int $lastVersion): string
    {
        $payload = json_encode([
            'wallet_id' => $walletId,
            'last_version' => $lastVersion,
            'paging_direction' => 'before',
            'filter_fingerprint' => $filters->fingerprint(),
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $this->signingKey(), true);

        return $this->base64UrlEncode($payload).'.'.$this->base64UrlEncode($signature);
    }

    private function decodeCursor(string $cursor, string $walletId, HistoryFilters $filters): int
    {
        $parts = explode('.', $cursor);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('The history cursor is invalid.');
        }

        [$payloadToken, $signatureToken] = $parts;
        $payload = $this->base64UrlDecode($payloadToken);
        $signature = $this->base64UrlDecode($signatureToken);
        $expected = hash_hmac('sha256', $payload, $this->signingKey(), true);

        if (! hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('The history cursor signature is invalid.');
        }

        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        if (
            ($data['wallet_id'] ?? null) !== $walletId
            || ($data['paging_direction'] ?? null) !== 'before'
            || ($data['filter_fingerprint'] ?? null) !== $filters->fingerprint()
            || ! is_int($data['last_version'] ?? null)
            || $data['last_version'] < 1
        ) {
            throw new InvalidArgumentException('The history cursor does not match this request.');
        }

        return $data['last_version'];
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new \LogicException('APP_KEY is required to sign history cursors.');
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('The history cursor encoding is invalid.');
        }

        return $decoded;
    }
}
