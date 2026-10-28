<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'published_at',
        'dead_lettered_at',
        'attempts',
        'available_at',
        'lease_owner',
        'leased_until',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'dead_lettered_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'leased_until' => 'immutable_datetime',
        ];
    }
}
