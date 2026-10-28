<?php

namespace App\Data\Wallet;

use App\Domain\Wallet\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class GatewayCallback
{
    private int $validatedAmountMinor;

    public function __construct(
        private string $gatewayReference,
        int $amountMinor,
    ) {
        if (! Str::isUuid($gatewayReference)) {
            throw new InvalidArgumentException('Gateway reference must be a UUID.');
        }

        $this->validatedAmountMinor = Money::fromMinor($amountMinor)->minor();
    }

    public function gatewayReference(): string
    {
        return $this->gatewayReference;
    }

    public function amountMinor(): int
    {
        return $this->validatedAmountMinor;
    }
}
