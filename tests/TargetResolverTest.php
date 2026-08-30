<?php

namespace SergeyBruhin\PostgresTools\Tests;

use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\TargetResolver;

class TargetResolverTest extends TestCase
{
    private function resolver(): TargetResolver
    {
        return $this->app->make(TargetResolver::class);
    }

    public function test_it_falls_back_to_the_default_connection(): void
    {
        $target = $this->resolver()->resolve();

        $this->assertSame('pgsql', $target->connection);
        $this->assertSame('app_db', $target->database);
        $this->assertSame('db.test', $target->host);
    }

    public function test_it_uses_the_named_connection(): void
    {
        $target = $this->resolver()->resolve('legacy');

        $this->assertSame('legacy_db', $target->database);
        $this->assertSame('legacy.test', $target->host);
        $this->assertSame(5433, $target->port);
    }

    public function test_database_override_swaps_only_the_name(): void
    {
        $target = $this->resolver()->resolve(null, 'homestead-dump-3');

        $this->assertSame('homestead-dump-3', $target->database);
        $this->assertSame('db.test', $target->host);
        $this->assertSame('app_user', $target->username);
        $this->assertSame('app_secret', $target->password);
    }

    public function test_the_config_connection_wins_over_the_app_default(): void
    {
        config(['postgres-tools.connection' => 'legacy']);

        $this->assertSame('legacy_db', $this->resolver()->resolve()->database);
    }

    public function test_an_explicit_connection_wins_over_the_config_connection(): void
    {
        config(['postgres-tools.connection' => 'legacy']);

        $this->assertSame('app_db', $this->resolver()->resolve('pgsql')->database);
    }

    public function test_it_refuses_a_non_postgres_driver(): void
    {
        $this->expectException(PostgresToolsException::class);
        $this->expectExceptionMessage('only pgsql is supported');

        $this->resolver()->resolve('mysql');
    }

    public function test_it_refuses_an_undefined_connection(): void
    {
        $this->expectException(PostgresToolsException::class);

        $this->resolver()->resolve('does-not-exist');
    }

    public function test_it_lists_only_postgres_connections(): void
    {
        $names = $this->resolver()->pgsqlConnectionNames();

        $this->assertContains('pgsql', $names);
        $this->assertContains('legacy', $names);
        $this->assertNotContains('mysql', $names);
    }

    public function test_the_maintenance_connection_points_at_the_postgres_database(): void
    {
        $target = $this->resolver()->resolve();
        $name   = $this->resolver()->maintenanceConnection($target);

        $this->assertSame('postgres', config("database.connections.{$name}.database"));
        $this->assertSame('db.test', config("database.connections.{$name}.host"));
    }
}
