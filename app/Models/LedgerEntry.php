<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ledger_transaction_id',
        'wallet_id',
        'amount_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function balanceState(): HasOne
    {
        return $this->hasOne(WalletBalanceState::class);
    }
}
