<?php

namespace SergeyBruhin\PostgresTools\Tests;

use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;

/**
 * resolveInDirectory() is what the Nova tool's HTTP endpoints call with a filename that came
 * straight from the request — it must never resolve to anything outside the backup directory,
 * unlike resolveFile()'s working-directory fallback, which exists for a trusted CLI operator.
 */
class BackupRepositoryTest extends TestCase
{
    private string $backups;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backups = sys_get_temp_dir() . '/pgtools-backups-' . bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir() . '/pgtools-outside-' . bin2hex(random_bytes(6));

        mkdir($this->backups, 0755, true);
        mkdir($this->outside, 0755, true);

        file_put_contents($this->backups . '/app-2026-01-01_000000.dump', 'PGDMP');
        file_put_contents($this->outside . '/.env', 'DB_PASSWORD=secret');
    }

    protected function tearDown(): void
    {
        foreach ([$this->backups, $this->outside] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function repository(): BackupRepository
    {
        return $this->app->make(BackupRepository::class);
    }

    public function test_it_resolves_a_real_dump_inside_the_directory(): void
    {
        $resolved = $this->repository()->resolveInDirectory('app-2026-01-01_000000.dump', $this->backups);

        $this->assertSame(realpath($this->backups . '/app-2026-01-01_000000.dump'), $resolved);
    }

    public function test_it_refuses_a_bare_filename_that_only_exists_outside_the_directory(): void
    {
        // This is the exact shape of the bug: a filename that resolves against the process's
        // cwd (or anywhere else on disk) rather than the backup directory it was asked to
        // search, handing back the application's own .env instead of "not found".
        $this->expectException(PostgresToolsException::class);
        $this->expectExceptionMessage('Dump not found: .env');

        $this->repository()->resolveInDirectory($this->outside . '/.env', $this->backups);
    }

    public function test_it_strips_directory_components_before_searching(): void
    {
        // basename() is applied first, so this looks for a file literally named ".env"
        // inside the backup directory — which does not exist — not the one next door.
        $this->expectException(PostgresToolsException::class);

        $this->repository()->resolveInDirectory('../' . basename($this->outside) . '/.env', $this->backups);
    }

    public function test_it_refuses_a_dump_that_does_not_exist(): void
    {
        $this->expectException(PostgresToolsException::class);

        $this->repository()->resolveInDirectory('nope.dump', $this->backups);
    }
}
