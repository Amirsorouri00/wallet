<?php

namespace App\Data\Wallet;

final readonly class HistoryCursorPage
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        private array $items,
        private ?string $nextCursor,
        private int $perPage,
    ) {}

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }
}
