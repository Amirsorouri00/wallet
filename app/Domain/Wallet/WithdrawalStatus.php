<?php

namespace App\Domain\Wallet;

enum WithdrawalStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case SETTLED = 'SETTLED';
    case REJECTED = 'REJECTED';
}
