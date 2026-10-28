<?php

namespace App\Services\Wallet;

use InvalidArgumentException;

final class ClearingBucketSelector
{
    public function bucketFor(string $depositId): int
    {
        $bucketCount = (int) config('wallet.clearing_wallet_count');

        if ($bucketCount < 1 || $bucketCount > 64) {
            throw new InvalidArgumentException('Clearing wallet count must be between 1 and 64.');
        }

        /** @var array{hash: int} $unpacked */
        $unpacked = unpack('Nhash', hash('sha256', $depositId, true));

        return $unpacked['hash'] % $bucketCount;
    }
}
