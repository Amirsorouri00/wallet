<?php

namespace App\Http\Requests;

use App\Domain\Wallet\Money;
use App\Http\Requests\Concerns\InteractsWithIdempotencyKey;
use Closure;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

#[BodyParameter('amount_minor', description: 'Positive integer amount in the currency minor unit.', required: true, type: 'int', infer: false, example: 80)]
class TransferRequest extends FormRequest
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
            'destination_wallet_id' => ['required', 'uuid', 'exists:wallets,id'],
            'amount_minor' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > Money::MAX_MINOR) {
                    $fail("The {$attribute} field must be an integer between 1 and ".Money::MAX_MINOR.'.');
                }
            }],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $source = $this->route('wallet');
            $sourceId = is_object($source) ? $source->id : $source;

            if ($sourceId === $this->input('destination_wallet_id')) {
                $validator->errors()->add('destination_wallet_id', 'The destination wallet must differ from the source wallet.');
            }
        }];
    }

    public function destinationWalletId(): string
    {
        return $this->string('destination_wallet_id')->toString();
    }

    public function amountMinor(): int
    {
        return $this->integer('amount_minor');
    }
}
