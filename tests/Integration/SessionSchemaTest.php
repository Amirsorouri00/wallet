<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class SessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_session_driver_has_its_contracted_table(): void
    {
        PostgresTestDatabase::assertPostgres();

        self::assertTrue(Schema::hasTable('sessions'));

        DB::table('sessions')->insert([
            'id' => 'schema-contract-session',
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'integration-test',
            'payload' => 'serialized-session-payload',
            'last_activity' => 1_798_000_000,
        ]);

        self::assertSame(
            'serialized-session-payload',
            DB::table('sessions')->where('id', 'schema-contract-session')->value('payload'),
        );
    }
}
