<?php

namespace App\Domain\Wallet;

enum LedgerReferenceType: string
{
    case DEPOSIT = 'deposit';
    case TRANSFER = 'transfer';
    case WITHDRAWAL_APPROVAL = 'withdrawal-approval';
    case WITHDRAWAL_COMPENSATION = 'withdrawal-compensation';
}
