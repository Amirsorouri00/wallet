<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE idempotency_records (
                id UUID PRIMARY KEY,
                actor_wallet_id UUID NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
                operation VARCHAR(32) NOT NULL,
                idempotency_key VARCHAR(128) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                result_status VARCHAR(16) NOT NULL,
                http_status SMALLINT NULL,
                response_body JSONB NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT idempotency_records_scope_unique
                    UNIQUE (actor_wallet_id, operation, idempotency_key),
                CONSTRAINT idempotency_records_operation_check
                    CHECK (operation IN ('create_deposit', 'transfer', 'withdrawal')),
                CONSTRAINT idempotency_records_key_nonempty_check
                    CHECK (btrim(idempotency_key) <> ''),
                CONSTRAINT idempotency_records_request_hash_check
                    CHECK (request_hash ~ '^[0-9a-f]{64}$'),
                CONSTRAINT idempotency_records_result_status_check
                    CHECK (result_status IN ('processing', 'succeeded', 'rejected')),
                CONSTRAINT idempotency_records_http_status_check
                    CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599),
                CONSTRAINT idempotency_records_result_fields_check CHECK (
                    (result_status = 'processing' AND http_status IS NULL AND response_body IS NULL)
                    OR
                    (result_status IN ('succeeded', 'rejected')
                        AND http_status IS NOT NULL
                        AND response_body IS NOT NULL)
                )
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS idempotency_records');
    }
};
