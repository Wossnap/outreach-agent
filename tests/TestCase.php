<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Stop the whole run if it is pointed at the wrong database.
     *
     * Checked here because this happens after the application is booted and
     * before setUpTraits() runs RefreshDatabase, which is the thing that drops
     * every table. See TestDatabase for what can point a run at the wrong
     * place, and why this exits rather than failing an assertion.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $refusal = TestDatabase::refusalFor(DB::connection()->getDatabaseName());

        if ($refusal !== null) {
            fwrite(STDERR, $refusal);

            exit(1);
        }
    }
}
