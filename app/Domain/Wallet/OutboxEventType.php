<?php

namespace App\Domain\Wallet;

enum OutboxEventType: string
{
    case DEPOSIT_PENDING = 'DepositPending';
    case DEPOSIT_SUCCEEDED = 'DepositSucceeded';
    case DEPOSIT_FAILED = 'DepositFailed';
    case TRANSFER_COMPLETED = 'TransferCompleted';
    case WITHDRAWAL_PENDING = 'WithdrawalPending';
    case WITHDRAWAL_APPROVED = 'WithdrawalApproved';
    case WITHDRAWAL_SUCCEEDED = 'WithdrawalSucceeded';
    case WITHDRAWAL_FAILED = 'WithdrawalFailed';
}
