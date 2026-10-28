<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE wallets DROP CONSTRAINT wallets_system_owner_id_check;
            ALTER TABLE wallets ADD CONSTRAINT wallets_system_owner_id_check CHECK (
                owner_type <> 'system'
                OR owner_id ~ '^(gateway-clearing-(0[0-9]|[1-5][0-9]|6[0-3])|withdrawal-suspense-(0[0-9]|1[0-5]))$'
            )
            SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE idempotency_records DROP CONSTRAINT idempotency_records_operation_check;
            ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_check CHECK (
                operation IN (
                    'create_deposit', 'transfer',
                    'request_withdrawal', 'approve_withdrawal',
                    'settle_withdrawal', 'reject_withdrawal'
                )
            )
            SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE outbox_messages DROP CONSTRAINT outbox_messages_event_type_check;
            ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_event_type_check CHECK (
                event_type IN (
                    'DepositPending', 'DepositSucceeded', 'DepositFailed',
                    'TransferCompleted', 'WithdrawalPending', 'WithdrawalApproved',
                    'WithdrawalSucceeded', 'WithdrawalFailed'
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE withdrawal_requests (
                id UUID PRIMARY KEY,
                wallet_id UUID NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
                suspense_wallet_id UUID NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
                amount_minor BIGINT NOT NULL,
                status VARCHAR(16) NOT NULL,
                approval_ledger_transaction_id UUID NULL REFERENCES ledger_transactions(id) ON DELETE RESTRICT,
                compensation_ledger_transaction_id UUID NULL REFERENCES ledger_transactions(id) ON DELETE RESTRICT,
                rejection_reason VARCHAR(255) NULL,
                approved_at TIMESTAMPTZ NULL,
                settled_at TIMESTAMPTZ NULL,
                rejected_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT withdrawal_requests_approval_ledger_unique UNIQUE (approval_ledger_transaction_id),
                CONSTRAINT withdrawal_requests_compensation_ledger_unique UNIQUE (compensation_ledger_transaction_id),
                CONSTRAINT withdrawal_requests_amount_positive_check CHECK (
                    amount_minor > 0 AND amount_minor <= 9000000000000000000
                ),
                CONSTRAINT withdrawal_requests_status_check CHECK (
                    status IN ('PENDING', 'APPROVED', 'SETTLED', 'REJECTED')
                ),
                CONSTRAINT withdrawal_requests_distinct_wallets_check CHECK (wallet_id <> suspense_wallet_id),
                CONSTRAINT withdrawal_requests_status_fields_check CHECK (
                    (status = 'PENDING'
                        AND approval_ledger_transaction_id IS NULL
                        AND compensation_ledger_transaction_id IS NULL
                        AND rejection_reason IS NULL
                        AND approved_at IS NULL
                        AND settled_at IS NULL
                        AND rejected_at IS NULL)
                    OR
                    (status = 'APPROVED'
                        AND approval_ledger_transaction_id IS NOT NULL
                        AND compensation_ledger_transaction_id IS NULL
                        AND rejection_reason IS NULL
                        AND approved_at IS NOT NULL
                        AND settled_at IS NULL
                        AND rejected_at IS NULL)
                    OR
                    (status = 'SETTLED'
                        AND approval_ledger_transaction_id IS NOT NULL
                        AND compensation_ledger_transaction_id IS NULL
                        AND rejection_reason IS NULL
                        AND approved_at IS NOT NULL
                        AND settled_at IS NOT NULL
                        AND rejected_at IS NULL)
                    OR
                    (status = 'REJECTED'
                        AND rejection_reason IS NOT NULL
                        AND btrim(rejection_reason) <> ''
                        AND settled_at IS NULL
                        AND rejected_at IS NOT NULL
                        AND (
                            (approval_ledger_transaction_id IS NULL
                                AND compensation_ledger_transaction_id IS NULL
                                AND approved_at IS NULL)
                            OR
                            (approval_ledger_transaction_id IS NOT NULL
                                AND compensation_ledger_transaction_id IS NOT NULL
                                AND approved_at IS NOT NULL)
                        ))
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX withdrawal_requests_wallet_created_index
                ON withdrawal_requests (wallet_id, created_at DESC, id DESC)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_withdrawal_wallets_and_identity()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND (
                    NEW.wallet_id IS DISTINCT FROM OLD.wallet_id
                    OR NEW.suspense_wallet_id IS DISTINCT FROM OLD.suspense_wallet_id
                    OR NEW.amount_minor IS DISTINCT FROM OLD.amount_minor
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '55000',
                        MESSAGE = 'withdrawal_requests_identity_immutable: financial identity cannot change';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM wallets
                    WHERE id = NEW.wallet_id AND owner_type = 'user' AND allows_negative = FALSE
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'withdrawal_requests_wallet_role_check: wallet_id must identify a user wallet';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM wallets
                    WHERE id = NEW.suspense_wallet_id
                      AND owner_type = 'system'
                      AND allows_negative = TRUE
                      AND owner_id LIKE 'withdrawal-suspense-%'
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'withdrawal_requests_suspense_role_check: suspense wallet is invalid';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER withdrawal_requests_validate_wallets_and_identity
            BEFORE INSERT OR UPDATE ON withdrawal_requests
            FOR EACH ROW EXECUTE FUNCTION validate_withdrawal_wallets_and_identity();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS withdrawal_requests');
        DB::statement('DROP FUNCTION IF EXISTS validate_withdrawal_wallets_and_identity()');
    }
};
