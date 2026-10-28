<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 24);
            $table->string('reference_type', 64);
            $table->uuid('reference_id');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_transactions
                ADD CONSTRAINT ledger_transactions_reference_unique UNIQUE (reference_type, reference_id),
                ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('deposit', 'transfer', 'withdrawal')),
                ADD CONSTRAINT ledger_transactions_reference_type_check CHECK (
                    reference_type IN (
                        'deposit',
                        'transfer',
                        'withdrawal-approval',
                        'withdrawal-compensation'
                    )
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
