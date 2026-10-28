<?php

namespace App\Http\Requests;

class RejectWithdrawalRequest extends WithdrawalCommandRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function reason(): string
    {
        return $this->string('reason')->toString();
    }
}
