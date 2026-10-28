<?php

namespace App\Contracts;

use App\Models\OutboxMessage;

interface EventPublisher
{
    public function publish(OutboxMessage $message): void;
}
