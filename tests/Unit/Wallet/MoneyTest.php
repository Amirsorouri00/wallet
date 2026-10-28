<?php

namespace Tests\Unit\Wallet;

use App\Domain\Wallet\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_one_minor_unit_is_preserved(): void
    {
        self::assertSame(1, Money::fromMinor(1)->minor());
    }

    public function test_the_configured_maximum_is_preserved(): void
    {
        self::assertSame(Money::MAX_MINOR, Money::fromMinor(Money::MAX_MINOR)->minor());
    }

    #[DataProvider('nonPositiveAmounts')]
    public function test_zero_and_negative_amounts_are_rejected(int $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor($amount);
    }

    /** @return array<string, array{int}> */
    public static function nonPositiveAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_amounts_above_the_configured_maximum_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMinor(Money::MAX_MINOR + 1);
    }

    public function test_checked_add_rejects_results_above_the_balance_ceiling(): void
    {
        $money = Money::fromMinor(Money::MAX_MINOR);

        $this->expectException(OverflowException::class);

        $money->checkedAdd(1);
    }

    public function test_checked_add_returns_the_exact_integer_result(): void
    {
        self::assertSame(70, Money::fromMinor(80)->checkedAdd(-10));
    }
}
