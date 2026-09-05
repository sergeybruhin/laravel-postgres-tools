<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\ManifestWriter;
use SergeyBruhin\PostgresTools\Services\RemoteRepository;

class VerifyBackupCommand extends Command
{
    protected $signature = 'pg:verify
        {file?        : Dump to check (omit to use the newest in the backup directory)}
        {--path=      : Backup directory to look in (default: config postgres-tools.path)}
        {--remote     : Check the copy on the offsite disk instead of the local one}
        {--disk=      : Disk to check (implies --remote; default: config postgres-tools.disk)}
        {--disk-path= : Directory within that disk (default: config postgres-tools.disk_path)}';

    protected $description = 'Check a dump against its manifest and confirm pg_restore can read it';

    public function handle(
        BackupRepository   $backups,
        ManifestWriter     $manifests,
        ConsistencyChecker $checker,
        RemoteRepository   $remote,
    ): int {
        if ((bool) $this->option('remote') || $this->option('disk') !== null) {
            return $this->verifyRemote($remote);
        }

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

    /**
     * Verifying an offsite copy without pulling it down: the checksum is streamed and
     * compared against the manifest stored beside it. The archive's table of contents
     * cannot be read this way — pg_restore needs a real file — so a remote check proves
     * the bytes are intact but not that pg_restore can parse them. Restoring with
     * --from-disk does both, because by then the dump is local.
     */
    private function verifyRemote(RemoteRepository $remote): int
    {
        $disk      = $this->option('disk');
        $directory = $this->option('disk-path');

        try {
            $file = $this->argument('file') !== null
                ? $this->remoteFile($remote, (string) $this->argument('file'), $disk, $directory)
                : ($remote->latest($disk, $directory)
                    ?? throw new PostgresToolsException(
                        'No dumps on disk [' . $remote->diskName($disk) . '] under ' . $remote->directory($directory) . '/.'
                    ));

            $this->newLine();
            $this->table(['Verify (offsite)', ''], [
                ['Disk', (string) $remote->diskName($disk)],
                ['Key', $file->path],
                ['Size', $file->humanSize()],
                ['Format', $file->format()],
                ['Manifest', $file->manifest === null ? 'missing' : 'present'],
            ]);

            if ($file->manifest === null) {
                $this->warn('No manifest beside it — the remote copy cannot be checksum-verified.');

                return self::FAILURE;
            }

            if ($file->manifest->bytes !== 0 && $file->manifest->bytes !== $file->bytes) {
                $this->error("Size mismatch: manifest records {$file->manifest->bytes} bytes, the object is {$file->bytes}.");

                return self::FAILURE;
            }

            $this->line('Hashing the object…');
            $actual = $remote->checksum($file->path, $disk);
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (!hash_equals($file->manifest->sha256, $actual)) {
            $this->error('Checksum mismatch — the offsite copy is corrupt or was truncated in transit.');
            $this->line('  expected ' . $file->manifest->sha256);
            $this->line('  actual   ' . $actual);

            return self::FAILURE;
        }

        $this->info('Checksum OK — the offsite copy matches the manifest byte for byte.');

        return self::SUCCESS;
    }

    /**
     * @throws PostgresToolsException
     */
    private function remoteFile(RemoteRepository $remote, string $name, ?string $disk, ?string $directory): BackupFile
    {
        $key = $remote->key($name, $directory);

        foreach ($remote->all($disk, $directory) as $file) {
            if ($file->path === $key) {
                return $file;
            }
        }

        throw new PostgresToolsException(
            'No dump named [' . basename($name) . '] on disk [' . $remote->diskName($disk) . '].'
        );
    }
}
