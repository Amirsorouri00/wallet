<?php

namespace Tests\Unit\Wallet;

use App\Domain\Wallet\IdempotencyKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdempotencyKeyTest extends TestCase
{
    public function test_visible_ascii_key_is_normalized(): void
    {
        self::assertSame('retry-123_ABC', IdempotencyKey::fromString('  retry-123_ABC  ')->value());
        self::assertSame(str_repeat('x', 128), IdempotencyKey::fromString(str_repeat('x', 128))->value());
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_key_is_rejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        IdempotencyKey::fromString($key);
    }

    /** @return array<string, array{string}> */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'only whitespace' => ['   '],
            'internal space' => ['retry key'],
            'newline' => ["retry\nkey"],
            'multibyte' => ['کلید'],
            'too long' => [str_repeat('x', 129)],
        ];
    }
}
