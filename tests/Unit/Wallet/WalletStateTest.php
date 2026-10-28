<?php

namespace Tests\Unit\Wallet;

use App\Domain\Wallet\Money;
use App\Models\WalletBalanceState;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WalletStateTest extends TestCase
{
    public function test_next_balance_is_exact_integer_arithmetic(): void
    {
        self::assertSame(50, WalletBalanceState::calculateBalanceAfter(80, -30));
    }

    #[DataProvider('overflowingBalances')]
    public function test_next_balance_rejects_the_configured_ceiling(int $before, int $delta): void
    {
        $this->expectException(OverflowException::class);

        WalletBalanceState::calculateBalanceAfter($before, $delta);
    }

    /** @return array<string, array{int, int}> */
    public static function overflowingBalances(): array
    {
        return [
            'positive overflow' => [Money::MAX_MINOR, 1],
            'negative overflow' => [-Money::MAX_MINOR, -1],
        ];
    }
}
