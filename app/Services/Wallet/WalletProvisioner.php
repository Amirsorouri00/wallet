<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

final class WalletProvisioner
{
    public function create(
        string $ownerType,
        string $ownerId,
        bool $allowsNegative = false,
        ?string $id = null,
    ): Wallet {
        return DB::transaction(function () use ($ownerType, $ownerId, $allowsNegative, $id): Wallet {
            $attributes = [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'allows_negative' => $allowsNegative,
            ];

            if ($id !== null) {
                $attributes['id'] = $id;
            }

            $wallet = Wallet::query()->create($attributes);

            $wallet->balanceStates()->create([
                'allows_negative' => $allowsNegative,
                'version' => 0,
                'previous_state_id' => null,
                'previous_version' => null,
                'ledger_entry_id' => null,
                'delta_minor' => 0,
                'balance_before_minor' => 0,
                'balance_after_minor' => 0,
            ]);

            return $wallet;
        }, attempts: 1);
    }
}
