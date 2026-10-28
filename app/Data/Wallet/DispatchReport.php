<?php

namespace App\Data\Wallet;

final readonly class DispatchReport
{
    public function __construct(
        public int $claimed,
        public int $published,
        public int $failed,
    ) {}
}
