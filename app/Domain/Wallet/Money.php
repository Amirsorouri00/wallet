<?php

namespace App\Domain\Wallet;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    public const int MAX_MINOR = 9_000_000_000_000_000_000;

    private function __construct(private int $amountMinor) {}

    public static function fromMinor(int $amount): self
    {
        if ($amount <= 0 || $amount > self::MAX_MINOR) {
            throw new InvalidArgumentException(sprintf(
                'Amount must be between 1 and %d minor units.',
                self::MAX_MINOR,
            ));
        }

        return new self($amount);
    }

    public function minor(): int
    {
        return $this->amountMinor;
    }

    public function checkedAdd(int $signedDelta): int
    {
        if (
            ($signedDelta > 0 && $this->amountMinor > self::MAX_MINOR - $signedDelta)
            || ($signedDelta < 0 && $this->amountMinor < -self::MAX_MINOR - $signedDelta)
        ) {
            throw new OverflowException('Balance arithmetic exceeds the configured integer ceiling.');
        }

        return $this->amountMinor + $signedDelta;
    }
}
