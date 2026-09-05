<?php

namespace SergeyBruhin\PostgresTools\Tests;

use Illuminate\Support\Facades\Event;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Events\BackupStale;

/**
 * pg:check answers one question — is there a recent, verifiable dump — and it answers it
 * from the files, never from the database, which is what lets it run when the database is
 * the thing that is broken. That is also why these tests need no server.
 */
class CheckCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/pgtools-check-' . bin2hex(random_bytes(6));
        mkdir($this->path, 0755, true);

        $this->app['config']->set('postgres-tools.path', $this->path);
        $this->app['config']->set('postgres-tools.max_age_hours', 26);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->path);

        parent::tearDown();
    }

    /** @param array<string, mixed> $manifestOverrides */
    private function makeDump(
        string $name = 'app-2026-01-01_000000.dump',
        ?int   $ageHours = 0,
        bool   $withManifest = true,
        array  $manifestOverrides = [],
    ): string {
        $path = $this->path . '/' . $name;
        file_put_contents($path, 'PGDMP' . str_repeat('x', 200));

        if ($withManifest) {
            file_put_contents(BackupFile::manifestPathFor($path), Manifest::fromArray(array_merge([
                'database'       => 'app_db',
                'format'         => 'custom',
                'server_version' => '16.4',
                'bytes'          => filesize($path),
                'sha256'         => hash_file('sha256', $path),
            ], $manifestOverrides))->toJson());
        }

        if ($ageHours) {
            touch($path, time() - $ageHours * 3600);
        }

        return $path;
    }

    public function test_it_passes_on_a_fresh_verifiable_dump(): void
    {
        Event::fake([BackupStale::class]);
        $this->makeDump();

        $this->artisan('pg:check')->assertSuccessful();

        Event::assertNotDispatched(BackupStale::class);
    }

    public function test_an_empty_backup_directory_is_a_failure_not_a_quiet_pass(): void
    {
        Event::fake([BackupStale::class]);

        $this->artisan('pg:check')
            ->expectsOutputToContain('No dumps at all')
            ->assertFailed();

        Event::assertDispatched(BackupStale::class);
    }

    public function test_a_dump_older_than_the_limit_is_stale(): void
    {
        Event::fake([BackupStale::class]);
        $this->makeDump(ageHours: 48);

        $this->artisan('pg:check')->assertFailed();

        Event::assertDispatched(
            BackupStale::class,
            static fn (BackupStale $event) => $event->ageHours > 26 && $event->newest !== null
        );
    }

    public function test_a_dump_with_no_manifest_cannot_be_trusted(): void
    {
        Event::fake([BackupStale::class]);
        $this->makeDump(withManifest: false);

        $this->artisan('pg:check')
            ->expectsOutputToContain('no manifest')
            ->assertFailed();

        Event::assertDispatched(BackupStale::class);
    }

    public function test_a_dump_whose_size_disagrees_with_its_manifest_is_rejected(): void
    {
        Event::fake([BackupStale::class]);
        $this->makeDump(manifestOverrides: ['bytes' => 999999]);

        $this->artisan('pg:check')->assertFailed();

        Event::assertDispatched(BackupStale::class);
    }

    public function test_checksum_verification_is_opt_in_and_catches_a_corrupt_dump(): void
    {
        Event::fake([BackupStale::class]);
        $path = $this->makeDump();

        // Same length, different bytes: the size check cannot see this one.
        file_put_contents($path, 'PGDMP' . str_repeat('y', 200));

        $this->artisan('pg:check')->assertSuccessful();
        $this->artisan('pg:check', ['--checksum' => true])->assertFailed();

        Event::assertDispatched(BackupStale::class, 1);
    }

    /**
     * Regression: --max-age=0 was read as "unset" and silently fell back to the configured
     * 26 hours, so the strictest possible limit behaved like the most lenient one.
     */
    public function test_an_explicit_zero_max_age_is_honoured_rather_than_treated_as_unset(): void
    {
        $this->makeDump(ageHours: 2);

        $this->artisan('pg:check')->assertSuccessful();
        $this->artisan('pg:check', ['--max-age' => '0'])->assertFailed();
    }

    public function test_quiet_ok_says_nothing_when_all_is_well(): void
    {
        $this->makeDump();

        $this->artisan('pg:check', ['--quiet-ok' => true])
            ->doesntExpectOutput()
            ->assertSuccessful();
    }
}
