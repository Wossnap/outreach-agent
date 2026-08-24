<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards against the suite running against a real database.
 *
 * docker-compose sets DB_* and QUEUE_CONNECTION as container environment
 * variables. PHP exposes those in $_SERVER, which Laravel reads before $_ENV,
 * and PHPUnit's <env> element does not write to $_SERVER — so without matching
 * <server> entries in phpunit.xml the suite connects to the live Postgres
 * database and RefreshDatabase drops every table in it.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_tests_run_against_in_memory_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_queue_runs_synchronously(): void
    {
        $this->assertSame('sync', config('queue.default'));
    }
}
