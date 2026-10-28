<?php

namespace App\Data\Wallet;

use App\Domain\Wallet\BalancedPosting;
use App\Domain\Wallet\LedgerReferenceType;
use App\Domain\Wallet\LedgerTransactionType;

final readonly class PostingCommand
{
    public function __construct(
        private LedgerTransactionType $type,
        private LedgerReferenceType $referenceType,
        private string $referenceId,
        private BalancedPosting $posting,
    ) {}

    public function type(): string
    {
        return $this->type->value;
    }

    public function referenceType(): string
    {
        return $this->referenceType->value;
    }

    public function referenceId(): string
    {
        return $this->referenceId;
    }

    public function posting(): BalancedPosting
    {
        return $this->posting;
    }
}
