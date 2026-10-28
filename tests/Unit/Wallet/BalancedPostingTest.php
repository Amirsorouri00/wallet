<?php

namespace Tests\Unit\Wallet;

use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\PostingLine;
use App\Exceptions\Wallet\InvalidPosting;
use PHPUnit\Framework\TestCase;

class BalancedPostingTest extends TestCase
{
    public function test_equal_debit_and_credit_are_accepted_in_order(): void
    {
        $lines = [
            new PostingLine('wallet-a', -80),
            new PostingLine('wallet-b', 80),
        ];

        $posting = BalancedPosting::fromLines($lines);

        self::assertSame($lines, $posting->lines());
        self::assertSame('wallet-a', $posting->lines()[0]->walletId());
        self::assertSame(-80, $posting->lines()[0]->amountMinor());
    }

    public function test_zero_line_is_rejected(): void
    {
        $this->expectException(InvalidPosting::class);

        new PostingLine('wallet-a', 0);
    }

    public function test_duplicate_wallet_is_rejected(): void
    {
        $this->expectException(InvalidPosting::class);

        BalancedPosting::fromLines([
            new PostingLine('wallet-a', -80),
            new PostingLine('wallet-a', 80),
        ]);
    }

    public function test_fewer_than_two_lines_are_rejected(): void
    {
        $this->expectException(InvalidPosting::class);

        BalancedPosting::fromLines([
            new PostingLine('wallet-a', 80),
        ]);
    }

    public function test_nonzero_sum_is_rejected(): void
    {
        $this->expectException(InvalidPosting::class);

        BalancedPosting::fromLines([
            new PostingLine('wallet-a', -80),
            new PostingLine('wallet-b', 79),
        ]);
    }
}
