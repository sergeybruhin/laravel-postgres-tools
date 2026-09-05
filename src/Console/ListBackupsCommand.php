<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\RemoteRepository;

class ListBackupsCommand extends Command
{
    protected $signature = 'pg:backups
        {--path=      : Directory to list (default: config postgres-tools.path)}
        {--remote     : List the offsite disk instead of the local directory}
        {--disk=      : Disk to list (implies --remote; default: config postgres-tools.disk)}
        {--disk-path= : Directory within that disk (default: config postgres-tools.disk_path)}
        {--checksum   : Verify every checksum, which reads each dump in full}';

    protected $description = 'List the Postgres dumps on disk with their manifests';

    public function handle(
        BackupRepository   $backups,
        ConsistencyChecker $checker,
        RemoteRepository   $remote,
    ): int {
        $offsite = (bool) $this->option('remote') || $this->option('disk') !== null;

        try {
            $path  = $offsite
                ? $remote->diskName($this->option('disk')) . ':' . $remote->directory($this->option('disk-path'))
                : $backups->path($this->option('path'));
            $files = $offsite
                ? $remote->all($this->option('disk'), $this->option('disk-path'))
                : $backups->all($backups->path($this->option('path')));
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($files === []) {
            $this->info("No dumps in {$path}.");

            return self::SUCCESS;
        }

        $rows  = [];
        $total = 0;

        try {
            foreach ($files as $file) {
                $manifest = $file->manifest;
                $total   += $file->bytes;

                $rows[] = [
                    $file->name(),
                    $file->humanSize(),
                    date('Y-m-d H:i', $file->mtime),
                    $file->format(),
                    $manifest?->database ?? '—',
                    $manifest?->serverVersion ?? '—',
                    $this->checksumLabel($checker, $remote, $file, $offsite),
                ];
            }
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['File', 'Size', 'Created', 'Format', 'Database', 'Server', 'Checksum'],
            $rows
        );

        $this->line(sprintf(
            '%d dump(s) in %s, %s total.',
            count($files),
            $path,
            BackupFile::formatBytes($total)
        ));

        return self::SUCCESS;
    }

    /**
     * Hashing a multi-gigabyte dump is not something to do on every listing, so the check
     * is opt-in and the column otherwise reports only whether a manifest exists.
     */
    private function checksumLabel(
        ConsistencyChecker $checker,
        RemoteRepository   $remote,
        BackupFile         $file,
        bool               $offsite,
    ): string {
        if ($file->manifest === null) {
            return 'no manifest';
        }

        if (!$this->option('checksum')) {
            return 'recorded';
        }

        // Offsite hashing streams the object rather than downloading it, but it still
        // reads every byte over the network, which on a metered bucket is not free.
        $actual = $offsite
            ? $remote->checksum($file->path, $this->option('disk'))
            : $checker->checksum($file->path);

        return hash_equals($file->manifest->sha256, $actual) ? 'OK' : 'BAD';
    }
}
