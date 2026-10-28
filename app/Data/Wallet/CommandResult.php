<?php

namespace App\Data\Wallet;

use App\Domain\Wallet\CommandError;
use App\Domain\Wallet\CommandOutcome;
use LogicException;

final readonly class CommandResult
{
    /** @param array<string, mixed> $body */
    private function __construct(
        private CommandOutcome $outcome,
        private array $body,
        private ?CommandError $error,
    ) {}

    /** @param array<string, mixed> $body */
    public static function created(array $body): self
    {
        return new self(CommandOutcome::CREATED, $body, null);
    }

    /** @param array<string, mixed> $body */
    public static function succeeded(array $body): self
    {
        return new self(CommandOutcome::SUCCEEDED, $body, null);
    }

    public static function rejected(CommandError $error): self
    {
        return new self(CommandOutcome::REJECTED, [], $error);
    }

    public function outcome(): CommandOutcome
    {
        return $this->outcome;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function error(): ?CommandError
    {
        return $this->error;
    }

    public function requiredError(): CommandError
    {
        return $this->error ?? throw new LogicException('A successful command has no error code.');
    }

    public function successful(): bool
    {
        return $this->outcome->successful();
    }
}
