<?php

namespace Database\Seeders;

use App\Models\Wallet;
use App\Services\Wallet\WalletProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(WalletProvisioner $provisioner): void
    {
        foreach (config('wallet.demo_user_wallets') as $wallet) {
            $this->ensureWallet(
                provisioner: $provisioner,
                id: $wallet['id'],
                ownerType: 'user',
                ownerId: $wallet['owner_id'],
                allowsNegative: false,
            );
        }

        for ($index = 0; $index < config('wallet.clearing_wallet_count'); $index++) {
            $this->ensureWallet(
                provisioner: $provisioner,
                id: sprintf('018f0000-0000-7000-8000-%012x', 0x1000 + $index),
                ownerType: 'system',
                ownerId: sprintf('gateway-clearing-%02d', $index),
                allowsNegative: true,
            );
        }

        for ($index = 0; $index < config('wallet.withdrawal_suspense_wallet_count'); $index++) {
            $this->ensureWallet(
                provisioner: $provisioner,
                id: sprintf('018f0000-0000-7000-8000-%012x', 0x2000 + $index),
                ownerType: 'system',
                ownerId: sprintf('withdrawal-suspense-%02d', $index),
                allowsNegative: true,
            );
        }
    }

    private function ensureWallet(
        WalletProvisioner $provisioner,
        string $id,
        string $ownerType,
        string $ownerId,
        bool $allowsNegative,
    ): void {
        $existing = Wallet::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();

        if ($existing === null) {
            $provisioner->create($ownerType, $ownerId, $allowsNegative, $id);

            return;
        }

        if ($existing->id !== $id || $existing->allows_negative !== $allowsNegative) {
            throw new RuntimeException(sprintf(
                'Seed wallet %s/%s does not match its deterministic contract.',
                $ownerType,
                $ownerId,
            ));
        }
    }
}
