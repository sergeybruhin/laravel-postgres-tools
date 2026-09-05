<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Events\BackupStale;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\RemoteRepository;

/**
 * The canary for the whole arrangement.
 *
 * pg:backup reports its own failures, but the failure that actually loses data is quieter
 * than that: a scheduler that stopped running, a container rebuilt without the client
 * binaries, a disk whose credentials expired. None of those produce a failed backup —
 * they produce no backup at all, and nothing to notice.
 *
 * So this asks the only question that matters, of the artefacts rather than the process:
 * is there a recent, verifiable dump? Exit code and event both answer it.
 */
class CheckCommand extends Command
{
    protected $signature = 'pg:check
        {--path=      : Backup directory to inspect (default: config postgres-tools.path)}
        {--remote     : Inspect the offsite disk instead of the local directory}
        {--disk=      : Disk to inspect (implies --remote; default: config postgres-tools.disk)}
        {--disk-path= : Directory within that disk (default: config postgres-tools.disk_path)}
        {--max-age=   : Hours before the newest dump counts as stale (default: config max_age_hours)}
        {--checksum   : Also verify the newest dump against its manifest, reading it in full}
        {--quiet-ok   : Print nothing when everything is fine, for cron and healthchecks}';

    protected $description = 'Fail when there is no recent, verifiable backup';

    public function handle(
        BackupRepository   $backups,
        RemoteRepository   $remote,
        ConsistencyChecker $checker,
        Dispatcher         $events,
    ): int {
        $offsite = (bool) $this->option('remote') || $this->option('disk') !== null;
        $maxAge  = $this->intOption('max-age', (int) config('postgres-tools.max_age_hours', 26));

        try {
            [$location, $newest] = $offsite
                ? [
                    $remote->diskName($this->option('disk')) . ':' . $remote->directory($this->option('disk-path')),
                    $remote->latest($this->option('disk'), $this->option('disk-path')),
                ]
                : [
                    $backups->path($this->option('path')),
                    $backups->latest($backups->path($this->option('path'))),
                ];
        } catch (PostgresToolsException $e) {
            return $this->stale($events, $e->getMessage(), $maxAge);
        }

        if ($newest === null) {
            return $this->stale($events, "No dumps at all in {$location}.", $maxAge);
        }

        $ageHours = round((time() - $newest->mtime) / 3600, 1);

        if ($newest->manifest === null) {
            return $this->stale(
                $events,
                "The newest dump ({$newest->name()}) has no manifest, so it cannot be verified.",
                $maxAge,
                $newest,
                $ageHours
            );
        }

        if ($ageHours > $maxAge) {
            return $this->stale(
                $events,
                "The newest dump ({$newest->name()}) is {$ageHours}h old; the limit is {$maxAge}h.",
                $maxAge,
                $newest,
                $ageHours
            );
        }

        if ($newest->manifest->bytes !== 0 && $newest->manifest->bytes !== $newest->bytes) {
            return $this->stale(
                $events,
                "The newest dump ({$newest->name()}) is {$newest->bytes} bytes; its manifest records {$newest->manifest->bytes}.",
                $maxAge,
                $newest,
                $ageHours
            );
        }

        if ($this->option('checksum')) {
            try {
                $actual = $offsite
                    ? $remote->checksum($newest->path, $this->option('disk'))
                    : $checker->checksum($newest->path);
            } catch (PostgresToolsException $e) {
                return $this->stale($events, $e->getMessage(), $maxAge, $newest, $ageHours);
            }

            if (!hash_equals($newest->manifest->sha256, $actual)) {
                return $this->stale(
                    $events,
                    "The newest dump ({$newest->name()}) does not match its recorded checksum.",
                    $maxAge,
                    $newest,
                    $ageHours
                );
            }
        }

        if (!$this->option('quiet-ok')) {
            $this->info(sprintf(
                'OK — %s, %s, %sh old (limit %sh)%s.',
                $newest->name(),
                BackupFile::formatBytes($newest->bytes),
                $ageHours,
                $maxAge,
                $this->option('checksum') ? ', checksum verified' : ''
            ));
            $this->line('  in ' . $location);
        }

        return self::SUCCESS;
    }

    /**
     * An option given as 0 means 0, not "unset". `?:` cannot tell those apart, and reading
     * `--max-age=0` as "use the configured 26 hours" is the wrong way round for a check
     * whose whole job is to fail.
     */
    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return ($value === null || $value === '') ? $default : (int) $value;
    }

    private function stale(
        Dispatcher  $events,
        string      $reason,
        int         $maxAge,
        ?BackupFile $newest = null,
        ?float      $ageHours = null,
    ): int {
        $events->dispatch(new BackupStale($reason, $maxAge, $newest, $ageHours));

        $this->error($reason);

        return self::FAILURE;
    }
}
