<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyRecord extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'actor_wallet_id',
        'operation',
        'idempotency_key',
        'request_hash',
        'result_status',
        'http_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function actorWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'actor_wallet_id');
    }
}
