<?php

namespace App\Http\Requests;

use App\Data\Wallet\HistoryFilters;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

#[QueryParameter('type', description: 'Ledger reference type filter.', type: "'deposit'|'transfer'|'withdrawal'", infer: false, example: 'transfer')]
#[QueryParameter('direction', description: 'Direction relative to the selected wallet.', type: "'credit'|'debit'", infer: false, example: 'debit')]
class WalletHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::in(['deposit', 'transfer', 'withdrawal'])],
            'direction' => ['nullable', Rule::in(['credit', 'debit'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (
                $this->filled('from')
                && $this->filled('to')
                && new DateTimeImmutable($this->string('from')->toString())
                    >= new DateTimeImmutable($this->string('to')->toString())
            ) {
                $validator->errors()->add('to', 'The to timestamp must be after from.');
            }
        }];
    }

    public function filters(): HistoryFilters
    {
        return new HistoryFilters(
            type: $this->filled('type') ? $this->string('type')->toString() : null,
            direction: $this->filled('direction') ? $this->string('direction')->toString() : null,
            from: $this->filled('from') ? new DateTimeImmutable($this->string('from')->toString()) : null,
            to: $this->filled('to') ? new DateTimeImmutable($this->string('to')->toString()) : null,
        );
    }

    public function cursor(): ?string
    {
        return $this->filled('cursor') ? $this->string('cursor')->toString() : null;
    }

    public function perPage(): int
    {
        return min(
            $this->integer('per_page', (int) config('wallet.history_page_size_default')),
            (int) config('wallet.history_page_size_max'),
        );
    }
}
