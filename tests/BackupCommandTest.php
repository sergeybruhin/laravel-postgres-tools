<?php

namespace SergeyBruhin\PostgresTools\Tests;

use SergeyBruhin\PostgresTools\Console\BackupCommand;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Target;

/**
 * The default filename starts with the site's own name, not the database's — "app_db" or
 * "homestead" says nothing about which of several sites a dump came from once it is sitting
 * in a shared bucket. Reached through reflection: the alternative is running the whole
 * command, which probes the real database connection before it ever builds a filename and
 * blocks for a long time against the fixture's unreachable host.
 */
class BackupCommandTest extends TestCase
{
    private function defaultName(string $appName, string $database = 'app_db'): string
    {
        $this->app['config']->set('app.name', $appName);

        $command = new BackupCommand();
        $method  = new \ReflectionMethod($command, 'defaultName');

        return $method->invoke(
            $command,
            new Target('pgsql', 'db.test', 5432, $database, 'user', 'pass'),
            new DumpOptions(),
        );
    }

    public function test_it_starts_with_a_lowercase_latin_slug_of_the_site_name(): void
    {
        $name = $this->defaultName('Sergey Bruhin — тест!');

        $this->assertStringStartsWith('sergey-bruhin-test-app_db-', $name);
    }

    public function test_a_plain_latin_site_name_is_only_lowercased(): void
    {
        $name = $this->defaultName('SergeyBruhinCom');

        $this->assertStringStartsWith('sergeybruhincom-app_db-', $name);
    }

    public function test_a_blank_or_symbols_only_site_name_falls_back_to_app(): void
    {
        $name = $this->defaultName('   ---   ');

        $this->assertStringStartsWith('app-app_db-', $name);
    }

    public function test_the_timestamp_and_extension_still_follow_the_site_and_database(): void
    {
        $name = $this->defaultName('My Site', 'homestead');

        $this->assertMatchesRegularExpression(
            '/^my-site-homestead-\d{4}-\d{2}-\d{2}_\d{6}\.dump$/',
            $name
        );
    }
}
