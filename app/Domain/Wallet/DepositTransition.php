<?php

namespace App\Domain\Wallet;

enum DepositTransition: string
{
    case APPLY = 'APPLY';
    case NOOP = 'NOOP';
    case CONFLICT = 'CONFLICT';
}
