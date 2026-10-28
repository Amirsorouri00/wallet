<?php

namespace Tests\Integration\Wallet;

use App\Services\Wallet\ClearingBucketSelector;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
class ClearingBucketDistributionTest extends TestCase
{
    public function test_ten_thousand_deterministic_ids_are_evenly_distributed_across_all_buckets(): void
    {
        config()->set('wallet.clearing_wallet_count', 64);
        $selector = app(ClearingBucketSelector::class);
        $counts = array_fill(0, 64, 0);

        for ($index = 0; $index < 10_000; $index++) {
            $depositId = sprintf('018f0000-0000-7000-8000-%012x', $index);
            $bucket = $selector->bucketFor($depositId);
            $counts[$bucket]++;
            self::assertSame($bucket, $selector->bucketFor($depositId));
        }

        $mean = 10_000 / 64;
        self::assertCount(64, array_filter($counts, static fn (int $count): bool => $count > 0));
        self::assertGreaterThanOrEqual($mean * 0.75, min($counts));
        self::assertLessThanOrEqual($mean * 1.25, max($counts));
    }
}
