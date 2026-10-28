<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE deposits (
                id UUID PRIMARY KEY,
                wallet_id UUID NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
                clearing_wallet_id UUID NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
                amount_minor BIGINT NOT NULL,
                status VARCHAR(16) NOT NULL,
                gateway_reference UUID NOT NULL,
                ledger_transaction_id UUID NULL REFERENCES ledger_transactions(id) ON DELETE RESTRICT,
                failure_reason VARCHAR(255) NULL,
                resolved_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT deposits_gateway_reference_unique UNIQUE (gateway_reference),
                CONSTRAINT deposits_ledger_transaction_unique UNIQUE (ledger_transaction_id),
                CONSTRAINT deposits_amount_positive_check CHECK (
                    amount_minor > 0 AND amount_minor <= 9000000000000000000
                ),
                CONSTRAINT deposits_status_check CHECK (status IN ('PENDING', 'SUCCEEDED', 'FAILED')),
                CONSTRAINT deposits_status_fields_check CHECK (
                    (status = 'PENDING'
                        AND ledger_transaction_id IS NULL
                        AND failure_reason IS NULL
                        AND resolved_at IS NULL)
                    OR
                    (status = 'SUCCEEDED'
                        AND ledger_transaction_id IS NOT NULL
                        AND failure_reason IS NULL
                        AND resolved_at IS NOT NULL)
                    OR
                    (status = 'FAILED'
                        AND ledger_transaction_id IS NULL
                        AND failure_reason IS NOT NULL
                        AND btrim(failure_reason) <> ''
                        AND resolved_at IS NOT NULL)
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX deposits_wallet_created_index
                ON deposits (wallet_id, created_at DESC, id DESC)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_deposit_wallets_and_identity()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.clearing_wallet_id IS DISTINCT FROM OLD.clearing_wallet_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '55000',
                        MESSAGE = 'deposits_clearing_wallet_immutable: clearing_wallet_id cannot change';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM wallets
                    WHERE id = NEW.wallet_id AND owner_type = 'user' AND allows_negative = FALSE
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'deposits_wallet_role_check: wallet_id must identify a user wallet';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM wallets
                    WHERE id = NEW.clearing_wallet_id AND owner_type = 'system' AND allows_negative = TRUE
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'deposits_clearing_wallet_role_check: clearing_wallet_id must identify a system wallet';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deposits_validate_wallets_and_identity
            BEFORE INSERT OR UPDATE ON deposits
            FOR EACH ROW EXECUTE FUNCTION validate_deposit_wallets_and_identity()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS deposits');
        DB::statement('DROP FUNCTION IF EXISTS validate_deposit_wallets_and_identity()');
    }
};
