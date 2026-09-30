<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Keep the test suite off the live database.
     *
     * bootstrap/app.php intentionally loads .env with a mutable Dotenv instance
     * ("Ensure .env variables take precedence over static Docker container
     * environment variables"), so .env's DB_DATABASE=rms overwrites whatever
     * PHPUnit's <env> entries inject — that is how the suite kept writing rows
     * into the production database. Pinning the connection here, after the
     * application has booted and before any test resolves a connection
     * (RefreshDatabase included), is the one override that survives that.
     *
     * Requires the `rms_testing` database to exist (cloned from `rms` once).
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'rms_testing');

        return $app;
    }
}
