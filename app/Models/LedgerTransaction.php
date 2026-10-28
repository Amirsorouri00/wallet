<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'type',
        'reference_type',
        'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
