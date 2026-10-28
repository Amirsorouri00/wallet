<?php

namespace App\Http\Requests;

use App\Domain\Wallet\Money;
use Closure;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('amount_minor', description: 'Exact integer amount originally assigned to the deposit.', required: true, type: 'int', infer: false, example: 125)]
class GatewayCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $expected = (string) config('wallet.gateway_secret');
        $provided = (string) $this->header('X-Gateway-Secret', '');

        return $expected !== '' && hash_equals($expected, $provided);
    }

    public function rules(): array
    {
        return [
            'gateway_reference' => ['required', 'uuid'],
            'amount_minor' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) || $value < 1 || $value > Money::MAX_MINOR) {
                    $fail("The {$attribute} field must be an integer between 1 and ".Money::MAX_MINOR.'.');
                }
            }],
        ];
    }

    public function gatewayReference(): string
    {
        return $this->string('gateway_reference')->toString();
    }

    public function amountMinor(): int
    {
        return $this->integer('amount_minor');
    }
}
