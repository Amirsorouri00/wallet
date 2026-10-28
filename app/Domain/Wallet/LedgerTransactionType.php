<?php

namespace App\Domain\Wallet;

enum LedgerTransactionType: string
{
    case DEPOSIT = 'deposit';
    case TRANSFER = 'transfer';
    case WITHDRAWAL = 'withdrawal';
}
