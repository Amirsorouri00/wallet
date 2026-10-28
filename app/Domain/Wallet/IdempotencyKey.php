<?php

namespace App\Domain\Wallet;

use InvalidArgumentException;

final readonly class IdempotencyKey
{
    public const int MAX_BYTES = 128;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match('/\A[!-~]{1,'.self::MAX_BYTES.'}\z/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Idempotency key must contain between 1 and 128 visible ASCII characters.',
            );
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
