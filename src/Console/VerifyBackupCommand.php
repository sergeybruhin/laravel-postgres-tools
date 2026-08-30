<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\ManifestWriter;

class VerifyBackupCommand extends Command
{
    protected $signature = 'pg:verify
        {file? : Dump to check (omit to use the newest in the backup directory)}
        {--path= : Backup directory to look in (default: config postgres-tools.path)}';

    protected $description = 'Check a dump against its manifest and confirm pg_restore can read it';

    public function handle(
        BackupRepository   $backups,
        ManifestWriter     $manifests,
        ConsistencyChecker $checker,
    ): int {
        $path = $backups->path($this->option('path'));

        try {
            $file = $this->argument('file') !== null
                ? $backups->resolveFile((string) $this->argument('file'), $path)
                : ($backups->latest($path)?->path ?? throw new PostgresToolsException("No dumps found in {$path}."));
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $manifest = $manifests->read($file);
        $bytes    = (int) filesize($file);
        $backup   = new BackupFile($file, $bytes, (int) filemtime($file), $manifest);

        $this->newLine();
        $this->table(['Verify', ''], [
            ['File', $file],
            ['Size', BackupFile::formatBytes($bytes)],
            ['Format', $backup->format()],
            ['Manifest', $manifest === null ? 'missing' : 'present'],
        ]);

        $ok = true;

        try {
            if ($manifest === null) {
                $this->warn('No manifest — the dump cannot be checksum-verified.');
                $ok = false;
            } else {
                if ($manifest->bytes !== 0 && $manifest->bytes !== $bytes) {
                    $this->error("Size mismatch: manifest records {$manifest->bytes} bytes, file is {$bytes}.");
                    $ok = false;
                }

                $checker->verifyChecksum($file, $manifest);
                $this->info('Checksum OK.');
            }

            if ($backup->format() === DumpOptions::FORMAT_CUSTOM) {
                $entries = $checker->verifyArchive($file);
                $this->info("pg_restore read {$entries} entries from the archive.");
            } else {
                $this->line('Plain-format dump — there is no archive index to read back.');
            }
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
