<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array TABLES = [
        'wallets',
        'ledger_transactions',
        'ledger_entries',
        'wallet_balance_states',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_immutable_financial_mutation()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = '55000',
                    MESSAGE = format('immutable financial table %I rejects %s', TG_TABLE_NAME, TG_OP);
            END;
            $$
            SQL);

        foreach (self::TABLES as $table) {
            DB::statement(sprintf(
                'CREATE TRIGGER %s_immutable_trigger BEFORE UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION reject_immutable_financial_mutation()',
                $table,
                $table,
            ));
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_immutable_trigger ON %s', $table, $table));
        }

        DB::statement('DROP FUNCTION IF EXISTS reject_immutable_financial_mutation()');
    }
};
