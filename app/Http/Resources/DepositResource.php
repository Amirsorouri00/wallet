<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'amount_minor' => $this->amount_minor,
            'status' => $this->status->value,
            'gateway_reference' => $this->gateway_reference,
            'ledger_transaction_id' => $this->ledger_transaction_id,
            'failure_reason' => $this->failure_reason,
            'resolved_at' => $this->resolved_at?->toAtomString(),
            'created_at' => $this->created_at->toAtomString(),
        ];
    }
}
