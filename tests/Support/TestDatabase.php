<?php

namespace Tests\Support;

/**
 * The only database this suite is allowed to touch.
 *
 * Every test wipes the database it is connected to, so the name it connected to
 * is the whole of the safety. phpunit.xml forces that name, but a cached
 * config file overrides everything phpunit sets: `php artisan config:cache`
 * writes the live connection into bootstrap/cache/config.php, and Laravel reads
 * that file in preference to any environment variable. On a machine that has
 * ever had its config cached, `php artisan test` would otherwise connect to
 * whatever the cache holds and drop every table in it.
 *
 * An assertion is not enough to stop that: a failing test is recorded and the
 * run carries on into the next one, which is the one that does the dropping. So
 * TestCase halts the process on a refusal rather than failing a test.
 */
final class TestDatabase
{
    /** Must match the DB_DATABASE forced in phpunit.xml. */
    public const NAME = 'outreach_test';

    /**
     * Why this connection must not be used, or null if it is the right one.
     */
    public static function refusalFor(string $database): ?string
    {
        if ($database === self::NAME) {
            return null;
        }

        return <<<TEXT

            The test suite is connected to "{$database}". It may only ever
            connect to "outreach_test". Every test drops every table, so this run was
            stopped before it did.

            The usual cause is a cached config, which overrides phpunit:

                php artisan config:clear

            TEXT;
    }
}
