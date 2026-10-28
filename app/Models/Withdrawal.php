<?php

namespace App\Models;

use App\Domain\Wallet\WithdrawalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasUuids;

    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'id',
        'wallet_id',
        'suspense_wallet_id',
        'amount_minor',
        'status',
        'approval_ledger_transaction_id',
        'compensation_ledger_transaction_id',
        'rejection_reason',
        'approved_at',
        'settled_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => WithdrawalStatus::class,
            'approved_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array{
     *     version: int,
     *     withdrawal_id: string,
     *     wallet_id: string,
     *     suspense_wallet_id: string,
     *     amount_minor: int,
     *     status: string,
     *     approval_ledger_transaction_id: string|null,
     *     compensation_ledger_transaction_id: string|null,
     *     rejection_reason: string|null,
     *     approved_at: string|null,
     *     settled_at: string|null,
     *     rejected_at: string|null,
     *     created_at: string|null
     * }
     */
    public function payload(): array
    {
        return [
            'version' => 1,
            'withdrawal_id' => $this->id,
            'wallet_id' => $this->wallet_id,
            'suspense_wallet_id' => $this->suspense_wallet_id,
            'amount_minor' => $this->amount_minor,
            'status' => $this->status->value,
            'approval_ledger_transaction_id' => $this->approval_ledger_transaction_id,
            'compensation_ledger_transaction_id' => $this->compensation_ledger_transaction_id,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->format('Y-m-d\TH:i:s.uP'),
            'settled_at' => $this->settled_at?->format('Y-m-d\TH:i:s.uP'),
            'rejected_at' => $this->rejected_at?->format('Y-m-d\TH:i:s.uP'),
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
