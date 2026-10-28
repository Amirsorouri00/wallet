<?php

namespace App\Models;

use App\Domain\Wallet\DepositStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'wallet_id',
        'clearing_wallet_id',
        'amount_minor',
        'status',
        'gateway_reference',
        'ledger_transaction_id',
        'failure_reason',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => DepositStatus::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function clearingWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'clearing_wallet_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
