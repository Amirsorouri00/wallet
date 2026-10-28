<?php

namespace App\Services\Wallet;

use App\Domain\Wallet\OutboxAggregateType;
use App\Domain\Wallet\OutboxEventType;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OutboxRecorder
{
    /** @param array<string, mixed> $payload */
    public function record(
        OutboxEventType $eventType,
        OutboxAggregateType $aggregateType,
        string $aggregateId,
        array $payload,
    ): OutboxMessage {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Outbox recording requires an active database transaction.');
        }

        return OutboxMessage::query()->create([
            'event_type' => $eventType->value,
            'aggregate_type' => $aggregateType->value,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'occurred_at' => now(),
            'available_at' => now(),
            'attempts' => 0,
        ]);
    }
}
