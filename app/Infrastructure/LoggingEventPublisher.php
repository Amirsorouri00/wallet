<?php

namespace App\Infrastructure;

use App\Contracts\EventPublisher;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\Log;

final class LoggingEventPublisher implements EventPublisher
{
    public function publish(OutboxMessage $message): void
    {
        Log::info('wallet-event', [
            'event_id' => $message->id,
            'event_type' => $message->event_type,
            'aggregate_type' => $message->aggregate_type,
            'aggregate_id' => $message->aggregate_id,
            'occurred_at' => $message->occurred_at?->format('Y-m-d\TH:i:s.uP'),
            'payload' => $message->payload,
        ]);
    }
}
