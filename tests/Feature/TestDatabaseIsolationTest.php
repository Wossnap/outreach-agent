<?php

namespace Tests\Feature;

use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * The guard that keeps this suite off a real database.
 *
 * Every test drops every table in whatever it is connected to, so the rule is
 * that it may only ever connect to one named database. TestCase enforces it by
 * stopping the process; what is checked here is that the rule itself answers
 * correctly, since the enforcement cannot be exercised from inside a test that
 * only runs when the rule has already passed.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_the_suites_own_database_is_allowed(): void
    {
        $this->assertNull(TestDatabase::refusalFor(TestDatabase::NAME));
    }

    public function test_any_other_database_is_refused_by_name(): void
    {
        $refusal = TestDatabase::refusalFor('outreach');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('outreach', $refusal);
        $this->assertStringContainsString('config:clear', $refusal);
    }

    public function test_the_run_reached_that_database_and_no_other(): void
    {
        $this->assertSame(TestDatabase::NAME, \DB::connection()->getDatabaseName());
    }

    public function test_queue_runs_synchronously(): void
    {
        $this->assertSame('sync', config('queue.default'));
    }
}
