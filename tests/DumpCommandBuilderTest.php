<?php

namespace SergeyBruhin\PostgresTools\Tests;

use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\DumpService;
use SergeyBruhin\PostgresTools\Services\RestoreService;
use SergeyBruhin\PostgresTools\Services\TargetResolver;

class DumpCommandBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The builders resolve the binary through BinaryLocator, which would otherwise
        // need a real pg_dump on PATH; /bin/echo is executable everywhere.
        config([
            'postgres-tools.binaries.pg_dump'    => '/bin/echo',
            'postgres-tools.binaries.pg_restore' => '/bin/echo',
        ]);
    }

    private function argv(DumpOptions $options): array
    {
        $target = $this->app->make(TargetResolver::class)->resolve();

        return $this->app->make(DumpService::class)->command($target, $options, '/tmp/out.dump');
    }

    public function test_it_builds_a_custom_format_dump(): void
    {
        $argv = $this->argv(new DumpOptions());

        $this->assertSame('/bin/echo', $argv[0]);
        $this->assertContains('--host=db.test', $argv);
        $this->assertContains('--port=5432', $argv);
        $this->assertContains('--username=app_user', $argv);
        $this->assertContains('--dbname=app_db', $argv);
        $this->assertContains('--format=c', $argv);
        $this->assertContains('--compress=6', $argv);
        $this->assertContains('--file=/tmp/out.dump', $argv);
        $this->assertContains('--no-owner', $argv);
        $this->assertContains('--no-privileges', $argv);
    }

    public function test_the_password_never_reaches_the_command_line(): void
    {
        foreach ($this->argv(new DumpOptions()) as $argument) {
            $this->assertStringNotContainsString('app_secret', $argument);
        }

        $this->assertContains('--no-password', $this->argv(new DumpOptions()));
    }

    public function test_plain_format_omits_the_compression_flag(): void
    {
        $argv = $this->argv(new DumpOptions(format: DumpOptions::FORMAT_PLAIN));

        $this->assertContains('--format=p', $argv);
        $this->assertSame([], array_filter($argv, static fn ($a) => str_starts_with($a, '--compress')));
    }

    public function test_exclusions_map_to_the_right_flags(): void
    {
        $argv = $this->argv(new DumpOptions(
            excludeTables: ['audit_log'],
            excludeTableData: ['telescope_entries', 'sessions'],
        ));

        $this->assertContains('--exclude-table=audit_log', $argv);
        $this->assertContains('--exclude-table-data=telescope_entries', $argv);
        $this->assertContains('--exclude-table-data=sessions', $argv);
    }

    public function test_only_tables_map_to_table_flags(): void
    {
        $argv = $this->argv(new DumpOptions(onlyTables: ['users', 'orders']));

        $this->assertContains('--table=users', $argv);
        $this->assertContains('--table=orders', $argv);
    }

    public function test_scope_flags(): void
    {
        $this->assertContains('--schema-only', $this->argv(new DumpOptions(schemaOnly: true)));
        $this->assertContains('--data-only', $this->argv(new DumpOptions(dataOnly: true)));
    }

    public function test_restore_builds_clean_and_parallel_flags(): void
    {
        $target = $this->app->make(TargetResolver::class)->resolve(null, 'scratch');
        $argv   = $this->app->make(RestoreService::class)->command($target, '/tmp/in.dump', 4, true);

        $this->assertContains('--dbname=scratch', $argv);
        $this->assertContains('--clean', $argv);
        $this->assertContains('--if-exists', $argv);
        $this->assertContains('--jobs=4', $argv);
        $this->assertContains('--no-owner', $argv);
        $this->assertSame('/tmp/in.dump', end($argv));
    }

    public function test_a_single_job_omits_the_parallel_flag(): void
    {
        $target = $this->app->make(TargetResolver::class)->resolve();
        $argv   = $this->app->make(RestoreService::class)->command($target, '/tmp/in.dump');

        $this->assertSame([], array_filter($argv, static fn ($a) => str_starts_with($a, '--jobs')));
        $this->assertNotContains('--clean', $argv);
    }

    public function test_schema_only_and_data_only_are_mutually_exclusive(): void
    {
        $this->expectException(PostgresToolsException::class);

        new DumpOptions(schemaOnly: true, dataOnly: true);
    }

    public function test_only_table_cannot_be_combined_with_exclusions(): void
    {
        $this->expectException(PostgresToolsException::class);

        new DumpOptions(excludeTables: ['x'], onlyTables: ['users']);
    }

    public function test_compression_is_bounded(): void
    {
        $this->expectException(PostgresToolsException::class);

        new DumpOptions(compress: 10);
    }

    public function test_an_unknown_format_is_rejected(): void
    {
        $this->expectException(PostgresToolsException::class);

        new DumpOptions(format: 'directory');
    }
}
