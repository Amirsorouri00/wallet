<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'owner_type',
        'owner_id',
        'allows_negative',
    ];

    protected function casts(): array
    {
        return [
            'allows_negative' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function balanceStates(): HasMany
    {
        return $this->hasMany(WalletBalanceState::class);
    }
}
