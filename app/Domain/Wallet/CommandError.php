<?php

namespace App\Domain\Wallet;

enum CommandError: string
{
    case INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
}
