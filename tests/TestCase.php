<?php

namespace SergeyBruhin\PostgresTools\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use SergeyBruhin\PostgresTools\Providers\PostgresToolsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PostgresToolsServiceProvider::class];
    }

    /**
     * The connections the tests resolve against. Nothing here is ever connected to — the
     * suite covers resolution, argv construction and verdicts, none of which touch a server.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');

        $app['config']->set('database.connections.pgsql', [
            'driver'   => 'pgsql',
            'host'     => 'db.test',
            'port'     => 5432,
            'database' => 'app_db',
            'username' => 'app_user',
            'password' => 'app_secret',
            'sslmode'  => 'prefer',
        ]);

        $app['config']->set('database.connections.legacy', [
            'driver'   => 'pgsql',
            'host'     => 'legacy.test',
            'port'     => 5433,
            'database' => 'legacy_db',
            'username' => 'legacy_user',
            'password' => 'legacy_secret',
        ]);

        // Present so the driver guard has something non-postgres to reject.
        $app['config']->set('database.connections.mysql', [
            'driver'   => 'mysql',
            'database' => 'nope',
        ]);

        $app['config']->set('postgres-tools.connection', null);
    }
}
