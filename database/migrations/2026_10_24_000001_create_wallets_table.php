<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 16);
            $table->string('owner_id', 128);
            $table->boolean('allows_negative');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE wallets
                ADD CONSTRAINT wallets_owner_identity_unique UNIQUE (owner_type, owner_id),
                ADD CONSTRAINT wallets_id_allows_negative_unique UNIQUE (id, allows_negative),
                ADD CONSTRAINT wallets_owner_type_check CHECK (owner_type IN ('user', 'system')),
                ADD CONSTRAINT wallets_owner_id_nonempty_check CHECK (btrim(owner_id) <> ''),
                ADD CONSTRAINT wallets_owner_policy_check CHECK (
                    (owner_type = 'user' AND allows_negative = FALSE)
                    OR (owner_type = 'system' AND allows_negative = TRUE)
                ),
                ADD CONSTRAINT wallets_system_owner_id_check CHECK (
                    owner_type <> 'system'
                    OR owner_id ~ '^gateway-clearing-(0[0-9]|[1-5][0-9]|6[0-3])$'
                )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
