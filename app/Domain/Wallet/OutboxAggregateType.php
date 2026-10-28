<?php

namespace App\Domain\Wallet;

enum OutboxAggregateType: string
{
    case DEPOSIT = 'deposit';
    case TRANSFER = 'transfer';
    case WITHDRAWAL = 'withdrawal';
}
