<?php

return [
    'problem_base_uri' => env('WALLET_PROBLEM_BASE_URI', 'https://taline.example/problems'),
    'max_minor' => 9_000_000_000_000_000_000,
    'clearing_wallet_count' => max(1, min(64, (int) env('WALLET_CLEARING_POOL_SIZE', 64))),
    'withdrawal_suspense_wallet_count' => max(1, min(16, (int) env('WALLET_WITHDRAWAL_SUSPENSE_POOL_SIZE', 16))),
    'gateway_secret' => env('GATEWAY_SECRET', 'development-gateway-secret'),
    'history_page_size_default' => max(1, min(100, (int) env('WALLET_HISTORY_PAGE_SIZE', 25))),
    'history_page_size_max' => max(1, min(100, (int) env('WALLET_HISTORY_PAGE_SIZE_MAX', 100))),
    'deadlock_retries' => max(1, min(10, (int) env('WALLET_DEADLOCK_RETRIES', 3))),
    'readiness' => [
        'max_outbox_lag_seconds' => max(1, min(86400, (int) env('WALLET_READINESS_MAX_OUTBOX_LAG_SECONDS', 60))),
    ],
    'rate_limits' => [
        'writes_per_minute' => max(1, (int) env('WALLET_RATE_LIMIT_WRITES_PER_MINUTE', 6000)),
        'gateway_callbacks_per_minute' => max(1, (int) env('WALLET_RATE_LIMIT_GATEWAY_PER_MINUTE', 12000)),
        'admin_commands_per_minute' => max(1, (int) env('WALLET_RATE_LIMIT_ADMIN_PER_MINUTE', 6000)),
        'reads_per_minute' => max(1, (int) env('WALLET_RATE_LIMIT_READS_PER_MINUTE', 60000)),
    ],
    'outbox' => [
        'batch_size' => max(1, min(1000, (int) env('WALLET_OUTBOX_BATCH_SIZE', 100))),
        'lease_seconds' => max(1, min(3600, (int) env('WALLET_OUTBOX_LEASE_SECONDS', 30))),
        'base_backoff_seconds' => max(1, min(300, (int) env('WALLET_OUTBOX_BASE_BACKOFF_SECONDS', 2))),
        'max_backoff_seconds' => max(1, min(86400, (int) env('WALLET_OUTBOX_MAX_BACKOFF_SECONDS', 300))),
        'max_attempts' => max(1, min(1000, (int) env('WALLET_OUTBOX_MAX_ATTEMPTS', 10))),
    ],
    'demo_user_wallets' => [
        [
            'id' => '018f0000-0000-7000-8000-000000000001',
            'owner_id' => 'demo-user-alice',
        ],
        [
            'id' => '018f0000-0000-7000-8000-000000000002',
            'owner_id' => 'demo-user-bob',
        ],
    ],
];
