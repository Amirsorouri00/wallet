<?php

namespace App\Domain\Wallet;

enum DepositStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';

    public function transitionTo(self $next): DepositTransition
    {
        if ($this === $next) {
            return DepositTransition::NOOP;
        }

        if ($this === self::PENDING && $next !== self::PENDING) {
            return DepositTransition::APPLY;
        }

        return DepositTransition::CONFLICT;
    }
}
