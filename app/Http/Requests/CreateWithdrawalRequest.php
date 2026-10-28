<?php

namespace App\Http\Requests;

use App\Domain\Wallet\Money;
use App\Http\Requests\Concerns\InteractsWithIdempotencyKey;
use Closure;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('amount_minor', description: 'Positive integer amount in the currency minor unit.', required: true, type: 'int', infer: false, example: 80)]
class CreateWithdrawalRequest extends FormRequest
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
            'amount_minor' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > Money::MAX_MINOR) {
                    $fail("The {$attribute} field must be an integer between 1 and ".Money::MAX_MINOR.'.');
                }
            }],
        ];
    }

    public function amountMinor(): int
    {
        return $this->integer('amount_minor');
    }
}
