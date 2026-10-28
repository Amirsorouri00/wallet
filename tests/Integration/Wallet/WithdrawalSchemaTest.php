<?php

namespace Tests\Integration\Wallet;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class WithdrawalSchemaTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        PostgresTestDatabase::assertPostgres();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_status_shape_amount_roles_and_identity_are_database_contracted(): void
    {
        $user = DB::table('wallets')->where('owner_type', 'user')->value('id');
        $suspense = DB::table('wallets')->where('owner_id', 'withdrawal-suspense-00')->value('id');
        $clearing = DB::table('wallets')->where('owner_id', 'gateway-clearing-00')->value('id');

        $this->assertConstraint('withdrawal_requests_amount_positive_check', fn () => $this->insertPending($user, $suspense, 0));
        $this->assertConstraint('withdrawal_requests_suspense_role_check', fn () => $this->insertPending($user, $clearing, 10));

        $id = $this->insertPending($user, $suspense, 10);
        $this->assertConstraint('withdrawal_requests_status_fields_check', fn () => DB::table('withdrawal_requests')
            ->where('id', $id)
            ->update(['status' => 'APPROVED']));
        $this->assertConstraint('withdrawal_requests_identity_immutable', fn () => DB::table('withdrawal_requests')
            ->where('id', $id)
            ->update(['amount_minor' => 11]));
    }

    private function insertPending(string $user, string $suspense, int $amount): string
    {
        $id = (string) Str::uuid7();
        DB::table('withdrawal_requests')->insert([
            'id' => $id,
            'wallet_id' => $user,
            'suspense_wallet_id' => $suspense,
            'amount_minor' => $amount,
            'status' => 'PENDING',
        ]);

        return $id;
    }

    private function assertConstraint(string $name, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$name} to reject the mutation.");
        } catch (QueryException $exception) {
            self::assertStringContainsString($name, $exception->getMessage());
        }
    }
}
