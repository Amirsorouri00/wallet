<?php

namespace App\Domain\Wallet;

use App\Exceptions\Wallet\InvalidPosting;

final readonly class PostingLine
{
    public function __construct(
        private string $walletId,
        private int $amountMinor,
    ) {
        if (trim($walletId) === '') {
            throw new InvalidPosting('Posting wallet ID must not be empty.');
        }

        if (
            $amountMinor === 0
            || $amountMinor > Money::MAX_MINOR
            || $amountMinor < -Money::MAX_MINOR
        ) {
            throw new InvalidPosting('Posting amount must be nonzero and within the configured integer ceiling.');
        }
    }

    public function walletId(): string
    {
        return $this->walletId;
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }
}
