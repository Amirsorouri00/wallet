<?php

namespace App\Services\Wallet;

use App\Models\Wallet;
use InvalidArgumentException;
use RuntimeException;

final class WithdrawalSuspenseSelector
{
    public function select(string $withdrawalId): Wallet
    {
        $count = (int) config('wallet.withdrawal_suspense_wallet_count');

        if ($count < 1 || $count > 16) {
            throw new InvalidArgumentException('Withdrawal suspense wallet count must be between 1 and 16.');
        }

        /** @var array{hash: int} $unpacked */
        $unpacked = unpack('Nhash', hash('sha256', $withdrawalId, true));
        $bucket = $unpacked['hash'] % $count;
        $wallet = Wallet::query()
            ->where('owner_type', 'system')
            ->where('owner_id', sprintf('withdrawal-suspense-%02d', $bucket))
            ->first();

        if ($wallet === null) {
            throw new RuntimeException('The selected withdrawal suspense wallet is not provisioned.');
        }

        return $wallet;
    }
}
