<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE outbox_messages (
                id UUID PRIMARY KEY,
                event_type VARCHAR(64) NOT NULL,
                aggregate_type VARCHAR(64) NOT NULL,
                aggregate_id UUID NOT NULL,
                payload JSONB NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at TIMESTAMPTZ NULL,
                dead_lettered_at TIMESTAMPTZ NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                lease_owner VARCHAR(128) NULL,
                leased_until TIMESTAMPTZ NULL,
                last_error TEXT NULL,
                CONSTRAINT outbox_messages_event_type_check CHECK (
                    event_type IN (
                        'DepositPending',
                        'DepositSucceeded',
                        'DepositFailed',
                        'TransferCompleted',
                        'WithdrawalPending',
                        'WithdrawalSucceeded',
                        'WithdrawalFailed'
                    )
                ),
                CONSTRAINT outbox_messages_aggregate_type_check
                    CHECK (aggregate_type IN ('deposit', 'transfer', 'withdrawal')),
                CONSTRAINT outbox_messages_payload_object_check
                    CHECK (jsonb_typeof(payload) = 'object'),
                CONSTRAINT outbox_messages_attempts_nonnegative_check CHECK (attempts >= 0),
                CONSTRAINT outbox_messages_terminal_state_check CHECK (
                    published_at IS NULL OR dead_lettered_at IS NULL
                ),
                CONSTRAINT outbox_messages_lease_fields_check CHECK (
                    (lease_owner IS NULL AND leased_until IS NULL)
                    OR
                    (lease_owner IS NOT NULL AND btrim(lease_owner) <> '' AND leased_until IS NOT NULL)
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX outbox_messages_unpublished_available_index
                ON outbox_messages (available_at, occurred_at, id)
                WHERE published_at IS NULL AND dead_lettered_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX outbox_messages_aggregate_index
                ON outbox_messages (aggregate_type, aggregate_id, occurred_at, id)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS outbox_messages');
    }
};
