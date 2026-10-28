<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Wallet\IdempotencyKey;

trait InteractsWithIdempotencyKey
{
    protected function prepareForValidation(): void
    {
        $this->merge(['_idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return list<string> */
    protected function idempotencyKeyRules(): array
    {
        return [
            'required',
            'string',
            'ascii',
            'max:'.IdempotencyKey::MAX_BYTES,
            'regex:/\A[!-~]+\z/D',
        ];
    }

    public function idempotencyKey(): string
    {
        return IdempotencyKey::fromString($this->string('_idempotency_key')->toString())->value();
    }
}
