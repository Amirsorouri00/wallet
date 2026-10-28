<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\OpenApiVersion;

return [
    'api_path' => 'api/v1',
    'export_path' => 'docs/openapi.json',
    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),
        'description' => 'Double-entry wallet ledger API with append-only balance projections, database-backed idempotency, and a transactional outbox.',
    ],

    'ui' => [
        'title' => 'Taline Wallet Ledger API',
    ],

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],
];
