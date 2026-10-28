<?php

namespace App\Domain\Wallet;

use App\Exceptions\Wallet\InvalidPosting;

final readonly class BalancedPosting
{
    /** @param list<PostingLine> $lines */
    private function __construct(private array $lines) {}

    /** @param array<array-key, mixed> $lines */
    public static function fromLines(array $lines): self
    {
        if (! array_is_list($lines) || count($lines) < 2) {
            throw new InvalidPosting('A posting requires an ordered list of at least two lines.');
        }

        $walletIds = [];
        $sum = 0;

        foreach ($lines as $line) {
            if (! $line instanceof PostingLine) {
                throw new InvalidPosting('Every posting line must be a PostingLine.');
            }

            if (isset($walletIds[$line->walletId()])) {
                throw new InvalidPosting('A wallet may appear only once in a posting.');
            }

            $walletIds[$line->walletId()] = true;
            $amount = $line->amountMinor();

            if (
                ($amount > 0 && $sum > Money::MAX_MINOR - $amount)
                || ($amount < 0 && $sum < -Money::MAX_MINOR - $amount)
            ) {
                throw new InvalidPosting('Posting sum exceeds the configured integer ceiling.');
            }

            $sum += $amount;
        }

        if ($sum !== 0) {
            throw new InvalidPosting('Posting lines must sum to zero.');
        }

        /** @var list<PostingLine> $lines */
        return new self($lines);
    }

    /** @return list<PostingLine> */
    public function lines(): array
    {
        return $this->lines;
    }
}
