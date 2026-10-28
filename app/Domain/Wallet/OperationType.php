<?php

namespace App\Domain\Wallet;

enum OperationType: string
{
    case CREATE_DEPOSIT = 'create_deposit';
    case TRANSFER = 'transfer';
    case REQUEST_WITHDRAWAL = 'request_withdrawal';
    case APPROVE_WITHDRAWAL = 'approve_withdrawal';
    case SETTLE_WITHDRAWAL = 'settle_withdrawal';
    case REJECT_WITHDRAWAL = 'reject_withdrawal';
}
