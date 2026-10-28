<?php

namespace App\Domain\Wallet;

enum CommandOutcome: string
{
    case CREATED = 'created';
    case SUCCEEDED = 'succeeded';
    case REJECTED = 'rejected';

    public function successful(): bool
    {
        return $this !== self::REJECTED;
    }
}
