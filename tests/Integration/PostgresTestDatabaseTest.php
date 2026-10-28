<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\PostgresTestDatabase;
use Tests\TestCase;

#[Group('integration')]
class PostgresTestDatabaseTest extends TestCase
{
    public function test_guard_accepts_the_dedicated_postgres_test_database(): void
    {
        PostgresTestDatabase::assertPostgres();

        $this->addToAssertionCount(1);
    }

    public function test_guard_refuses_sqlite(): void
    {
        DB::shouldReceive('getDriverName')
            ->once()
            ->andReturn('sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL');

        PostgresTestDatabase::assertPostgres();
    }
}
