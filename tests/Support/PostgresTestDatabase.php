<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresTestDatabase
{
    public static function assertPostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Integration tests require PostgreSQL.');
        }

        $databaseName = DB::getDatabaseName();

        if (! is_string($databaseName) || ! str_ends_with($databaseName, '_test')) {
            throw new RuntimeException('Integration tests require a database whose name ends with _test.');
        }
    }
}
