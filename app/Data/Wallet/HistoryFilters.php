<?php

namespace App\Data\Wallet;

use DateTimeImmutable;

final readonly class HistoryFilters
{
    public function __construct(
        private ?string $type,
        private ?string $direction,
        private ?DateTimeImmutable $from,
        private ?DateTimeImmutable $to,
    ) {}

    public function type(): ?string
    {
        return $this->type;
    }

    public function direction(): ?string
    {
        return $this->direction;
    }

    public function from(): ?DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): ?DateTimeImmutable
    {
        return $this->to;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'direction' => $this->direction,
            'from' => $this->from?->format('Y-m-d\TH:i:s.uP'),
            'to' => $this->to?->format('Y-m-d\TH:i:s.uP'),
            'type' => $this->type,
        ], JSON_THROW_ON_ERROR));
    }
}
