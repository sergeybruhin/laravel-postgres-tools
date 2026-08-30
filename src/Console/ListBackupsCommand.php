<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;

class ListBackupsCommand extends Command
{
    protected $signature = 'pg:backups
        {--path=    : Directory to list (default: config postgres-tools.path)}
        {--checksum : Verify every checksum, which reads each dump in full}';

    protected $description = 'List the Postgres dumps on disk with their manifests';

    public function handle(BackupRepository $backups, ConsistencyChecker $checker): int
    {
        $path  = $backups->path($this->option('path'));
        $files = $backups->all($path);

        if ($files === []) {
            $this->info("No dumps in {$path}.");

            return self::SUCCESS;
        }

        $rows  = [];
        $total = 0;

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
                $this->checksumLabel($checker, $file),
            ];
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
    private function checksumLabel(ConsistencyChecker $checker, BackupFile $file): string
    {
        if ($file->manifest === null) {
            return 'no manifest';
        }

        if (!$this->option('checksum')) {
            return 'recorded';
        }

        return hash_equals($file->manifest->sha256, $checker->checksum($file->path)) ? 'OK' : 'BAD';
    }
}
