<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\InteractsWithIdempotencyKey;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawalCommandRequest extends FormRequest
{
    use InteractsWithIdempotencyKey;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            '_idempotency_key' => $this->idempotencyKeyRules(),
        ];
    }
}
