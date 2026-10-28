<?php

namespace App\Data\Wallet;

final readonly class PostingResult
{
    /**
     * @param  array<string, int>  $entryIdsByWallet
     * @param  array<string, int>  $stateIdsByWallet
     */
    public function __construct(
        private string $transactionId,
        private array $entryIdsByWallet,
        private array $stateIdsByWallet,
    ) {}

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    /** @return array<string, int> */
    public function entryIdsByWallet(): array
    {
        return $this->entryIdsByWallet;
    }

    /** @return array<string, int> */
    public function stateIdsByWallet(): array
    {
        return $this->stateIdsByWallet;
    }
}
