<?php

namespace App\Models;

use App\Domain\Wallet\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OverflowException;

class WalletBalanceState extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'wallet_id',
        'allows_negative',
        'version',
        'previous_state_id',
        'previous_version',
        'ledger_entry_id',
        'delta_minor',
        'balance_before_minor',
        'balance_after_minor',
    ];

    protected function casts(): array
    {
        return [
            'allows_negative' => 'boolean',
            'version' => 'integer',
            'previous_state_id' => 'integer',
            'previous_version' => 'integer',
            'ledger_entry_id' => 'integer',
            'delta_minor' => 'integer',
            'balance_before_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public static function calculateBalanceAfter(int $balanceBeforeMinor, int $deltaMinor): int
    {
        if (
            $balanceBeforeMinor > Money::MAX_MINOR
            || $balanceBeforeMinor < -Money::MAX_MINOR
            || ($deltaMinor > 0 && $balanceBeforeMinor > Money::MAX_MINOR - $deltaMinor)
            || ($deltaMinor < 0 && $balanceBeforeMinor < -Money::MAX_MINOR - $deltaMinor)
        ) {
            throw new OverflowException('Wallet balance exceeds the configured integer ceiling.');
        }

        return $balanceBeforeMinor + $deltaMinor;
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_state_id');
    }

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'previous_state_id');
    }
}
