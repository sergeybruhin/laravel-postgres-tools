<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use SergeyBruhin\PostgresTools\Events\RestoreCompleted;
use SergeyBruhin\PostgresTools\Events\RestoreFailed;
use SergeyBruhin\PostgresTools\Events\RestoreStarted;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\EnvironmentReport;
use SergeyBruhin\PostgresTools\Services\ManifestWriter;
use SergeyBruhin\PostgresTools\Services\RemoteRepository;
use SergeyBruhin\PostgresTools\Services\RestoreService;
use SergeyBruhin\PostgresTools\Services\TargetResolver;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'pg:restore
        {file?         : Dump to restore (omit to use the newest in the backup directory)}
        {--connection= : Laravel connection to restore into}
        {--database=   : Override the database name within that connection}
        {--path=       : Backup directory to look in (default: config postgres-tools.path)}
        {--from-disk   : Fetch the dump from the offsite disk first (file defaults to the newest there)}
        {--disk=       : Disk to fetch from (implies --from-disk; default: config postgres-tools.disk)}
        {--disk-path=  : Directory within that disk (default: config postgres-tools.disk_path)}
        {--keep-download : Keep the downloaded copy in the backup directory afterwards}
        {--drop        : DROP and CREATE the target database before restoring}
        {--clean       : pg_restore --clean --if-exists instead of dropping the database}
        {--jobs=1      : Parallel restore workers (custom format only)}
        {--skip-verify : Skip the checksum and archive checks}
        {--skip-checks : Skip post-restore row-count and migration reconciliation}
        {--dry-run     : Show what would happen and exit}
        {--force       : Skip interactive confirmation}';

    protected $description = 'Restore a Postgres dump, verifying it first and reconciling row counts afterwards';

    /** Staging directory for dumps pulled off a disk, inside the backup directory. */
    private const INCOMING = '.incoming';

    /** Set when the dump was fetched from a disk, so it can be cleaned up afterwards. */
    private ?string $downloaded = null;

    public function handle(
        TargetResolver     $resolver,
        EnvironmentReport  $report,
        BackupRepository   $backups,
        RestoreService     $restores,
        ManifestWriter     $manifests,
        ConsistencyChecker $checker,
        RemoteRepository   $remote,
        Dispatcher         $events,
    ): int {
        if (($guard = $this->guardEnvironment()) !== null) {
            return $guard;
        }

        try {
            $target = $resolver->resolve($this->option('connection'), $this->option('database'));
            $path   = $backups->path($this->option('path'));
            $file   = $this->fromDisk()
                ? $this->fetch($remote, $backups, $path)
                : $this->resolveFile($backups, $path);
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $manifest = $manifests->read($file);

        // A target database that does not exist yet is fine here — --drop is about to
        // create it — so the existence check is relaxed for the restoring side.
        $findings = $report->findings($target, $path, requireDatabase: false);

        $exists = $checker->databaseExists($target);
        $server = $report->server($target);

        if ($manifest !== null) {
            $findings[] = $checker->versionFinding($manifest, $server['major']);
        }

        if ($exists && !$this->option('drop') && !$this->option('clean') && $checker->tableCount($target) > 0) {
            $findings[] = Finding::warn(
                "{$target->database} already contains tables; restoring on top of them will collide.",
                'Pass --drop to recreate the database, or --clean to drop objects as they are replaced.'
            );
        }

        $this->summary($target, $file, $manifest, $server, $exists);

        if ($this->printFindings($findings) === self::FAILURE) {
            $events->dispatch(new RestoreFailed(
                $target,
                $file,
                new PostgresToolsException('Refused before restoring; see the findings above.'),
                0.0
            ));

            return self::FAILURE;
        }

        if (!$this->option('skip-verify') && ($verify = $this->verify($checker, $file, $manifest)) !== self::SUCCESS) {
            return $verify;
        }

        if ($this->option('dry-run')) {
            $this->dryRun($restores, $target, $file, $manifest);

            return self::SUCCESS;
        }

        if (!$this->confirmDestruction($target)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $events->dispatch(new RestoreStarted($target, $file, $manifest));
        $started = microtime(true);

        try {
            if ($this->option('drop')) {
                $this->line("Recreating database {$target->database}…");
                $restores->recreateDatabase($target);
            } elseif ($restores->createDatabaseIfMissing($target)) {
                $this->line("Created database {$target->database}.");
            }

            $plain = $this->formatOf($file, $manifest) === DumpOptions::FORMAT_PLAIN;
            $echo  = function (string $line): void {
                $this->line('  ' . $line);
            };

            if ($plain) {
                $this->line('Running psql…');
                $warnings = $restores->restorePlain($target, $file, $echo);
            } else {
                $this->line('Running pg_restore…');
                $warnings = $restores->restore(
                    target: $target,
                    file: $file,
                    jobs: max(1, (int) $this->option('jobs')),
                    clean: (bool) $this->option('clean'),
                    onOutput: $echo,
                );
            }

            $elapsed = round(microtime(true) - $started, 1);
            $this->info("Restored in {$elapsed}s." . ($warnings === [] ? '' : ' ' . count($warnings) . ' warning(s).'));
        } catch (Throwable $e) {
            // A restore that stops half-way leaves the target in an unusable state, so this
            // event matters more than its backup counterpart: something is down, not stale.
            $events->dispatch(new RestoreFailed($target, $file, $e, round(microtime(true) - $started, 1)));

            if (!$e instanceof PostgresToolsException) {
                throw $e;
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        ['differences' => $differences, 'pending' => $pending] = $this->option('skip-checks')
            ? ['differences' => [], 'pending' => []]
            : $this->reconcile($checker, $target, $manifest);

        $events->dispatch(new RestoreCompleted(
            target:              $target,
            file:                $file,
            seconds:             round(microtime(true) - $started, 1),
            manifest:            $manifest,
            warnings:            $warnings,
            rowCountDifferences: $differences,
            pendingMigrations:   $pending,
        ));

        $this->discardDownload($backups, $file);

        return self::SUCCESS;
    }

    private function fromDisk(): bool
    {
        return (bool) $this->option('from-disk') || $this->option('disk') !== null;
    }

    /**
     * Pull the dump local before doing anything else. Everything downstream — checksum,
     * archive parse, pg_restore itself — needs a real file, so the download is not an
     * alternative path through the command, just a step in front of the existing one.
     *
     * @throws PostgresToolsException
     */
    private function fetch(RemoteRepository $remote, BackupRepository $backups, string $path): string
    {
        $disk      = $this->option('disk');
        $directory = $this->option('disk-path');

        $name = $this->argument('file')
            ?? ($remote->latest($disk, $directory)?->name()
                ?? throw new PostgresToolsException(
                    'No dumps on disk [' . $remote->diskName($disk) . '] under ' . $remote->directory($directory) . '/.'
                ));

        // Never straight into the backup directory: a dump fetched from the disk usually
        // has the same filename as the local copy it was made from, and landing on top of
        // it would overwrite a real backup with a temporary one — which the cleanup below
        // would then delete. The staging directory is dotted, so listings skip it.
        $incoming = $path . '/' . self::INCOMING;
        $backups->ensureDirectory($incoming);

        // A restore that fails part-way leaves its download staged on purpose: retrying
        // should not pay to pull a multi-gigabyte dump down a second time. That means the
        // staging area is cleared on the way in rather than on the way out, so at most one
        // abandoned dump is ever holding space.
        foreach (glob($incoming . '/*') ?: [] as $leftover) {
            is_file($leftover) && @unlink($leftover);
        }

        $file = $remote->download((string) $name, $incoming, $disk, $directory, function (string $line): void {
            $this->line($line);
        });

        $this->downloaded = $file;
        $this->info('Fetched ' . basename($file) . ' from ' . $remote->diskName($disk) . '.');

        return $file;
    }

    /**
     * A dump pulled from the disk is a working copy, not a backup: leaving it behind grows
     * the local directory outside whatever retention pg:backup applies, and on a restore
     * host that is often the smallest volume around. --keep-download opts out.
     */
    private function discardDownload(BackupRepository $backups, string $file): void
    {
        if ($this->downloaded === null) {
            return;
        }

        $incoming = dirname($file);

        if ($this->option('keep-download')) {
            $this->keepDownload($backups, $file, $incoming);

            return;
        }

        @unlink(BackupFile::manifestPathFor($file));

        if (@unlink($file)) {
            $this->line('Removed the downloaded copy. Pass --keep-download to keep it next time.');
        }

        @rmdir($incoming);
    }

    /**
     * Promote the staged copy into the backup directory, unless something is already
     * sitting under that name — in which case the existing dump wins and the staged one
     * is left where it is, named in full, rather than quietly replacing a real backup.
     */
    private function keepDownload(BackupRepository $backups, string $file, string $incoming): void
    {
        $destination = $backups->path($this->option('path')) . '/' . basename($file);

        if (file_exists($destination)) {
            $this->warn('Kept the downloaded copy at ' . $file . '.');
            $this->line('  ' . $destination . ' already exists and was left untouched.');

            return;
        }

        @rename(BackupFile::manifestPathFor($file), BackupFile::manifestPathFor($destination));

        if (@rename($file, $destination)) {
            $this->line('Kept the downloaded copy at ' . $destination . '.');
            @rmdir($incoming);
        }
    }

    /**
     * Restoring over a production database is almost never what anyone means to do, so it
     * takes both --force and an explicit config opt-in rather than a prompt someone can
     * click through at 2am.
     */
    private function guardEnvironment(): ?int
    {
        if (!$this->getLaravel()->environment('production')) {
            return null;
        }

        $allowed = (bool) config('postgres-tools.allow_production_restore', false);

        if ($allowed && $this->option('force')) {
            $this->warn('APP_ENV=production — proceeding because --force and allow_production_restore are both set.');

            return null;
        }

        $this->error('Refusing to restore while APP_ENV=production.');
        $this->line('This would overwrite live data. If that is genuinely the intent, set');
        $this->line('PG_TOOLS_ALLOW_PRODUCTION_RESTORE=true and pass --force.');

        return self::FAILURE;
    }

    /**
     * @throws PostgresToolsException
     */
    private function resolveFile(BackupRepository $backups, string $path): string
    {
        $argument = $this->argument('file');

        if ($argument !== null) {
            return $backups->resolveFile((string) $argument, $path);
        }

        $latest = $backups->latest($path);

        if ($latest === null) {
            throw new PostgresToolsException(
                "No dumps found in {$path}. Pass a file explicitly, or run pg:backup first."
            );
        }

        return $latest->path;
    }

    /** @param array<string, mixed> $server */
    private function summary(Target $target, string $file, ?Manifest $manifest, array $server, bool $exists): void
    {
        $bytes = is_file($file) ? (int) filesize($file) : 0;

        $rows = [
            ['Target', $target->describe() . ($exists ? '' : '  (will be created)')],
            ['Connection', $target->connection],
            ['Server', $server['version'] ?? '—'],
            ['Dump', $file],
            ['Dump size', BackupFile::formatBytes($bytes)],
        ];

        if ($manifest !== null) {
            $rows[] = ['Taken from', $manifest->database . ' @ ' . $manifest->host];
            $rows[] = ['Taken at', $manifest->createdAt . ' (' . $manifest->appEnv . ')'];
            $rows[] = ['Dump server', $manifest->serverVersion];
            $rows[] = ['Tables', (string) count($manifest->rowCounts)];
            $rows[] = ['Emptied tables', $manifest->excludedTableData === [] ? '—' : implode(', ', $manifest->excludedTableData)];
            $rows[] = ['App version', $manifest->appVersion ?? '—'];
        } else {
            $rows[] = ['Manifest', 'none — this dump cannot be checksum-verified'];
        }

        $rows[] = ['Mode', match (true) {
            (bool) $this->option('drop')  => 'DROP and CREATE the database',
            (bool) $this->option('clean') => 'drop each object as it is replaced',
            default                       => 'load into the database as it stands',
        }];

        $this->newLine();
        $this->table(['Restore', ''], $rows);
    }

    private function verify(ConsistencyChecker $checker, string $file, ?Manifest $manifest): int
    {
        try {
            if ($manifest === null) {
                $this->warn('No manifest alongside this dump — skipping the checksum check.');
            } else {
                $checker->verifyChecksum($file, $manifest);
                $this->info('Checksum matches the manifest.');
            }

            if ($this->formatOf($file, $manifest) === DumpOptions::FORMAT_CUSTOM) {
                $entries = $checker->verifyArchive($file);
                $this->info("Archive readable: {$entries} entries.");
            } else {
                $this->line('Plain-format dump — there is no archive index to read back.');
            }
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Trusts the manifest when there is one, otherwise sniffs the archive's magic bytes. */
    private function formatOf(string $file, ?Manifest $manifest): string
    {
        return (new BackupFile($file, 0, 0, $manifest))->format();
    }

    private function dryRun(RestoreService $restores, Target $target, string $file, ?Manifest $manifest): void
    {
        if ($this->formatOf($file, $manifest) === DumpOptions::FORMAT_PLAIN) {
            $this->line('<comment>Would pipe this plain-SQL dump through psql</comment>; --jobs and --clean do not apply.');
            $this->info('Dry run — nothing was changed.');

            return;
        }

        try {
            $argv = $restores->command($target, $file, max(1, (int) $this->option('jobs')), (bool) $this->option('clean'));
        } catch (PostgresToolsException $e) {
            $this->warn('Cannot build the pg_restore invocation:');
            $this->line('  ' . str_replace(PHP_EOL, PHP_EOL . '  ', $e->getMessage()));

            return;
        }

        if ($this->option('drop')) {
            $this->line('<comment>Would drop and recreate</comment> ' . $target->database . ' over PDO, then run:');
        } else {
            $this->line('<comment>Would run</comment> (credentials travel via PGPASSFILE, not argv):');
        }

        $this->line('  ' . implode(" \\\n    ", $argv));
        $this->newLine();
        $this->info('Dry run — nothing was changed.');
    }

    /**
     * A plain yes/no is enough for a scratch database. When the target is the database this
     * app is actually configured to use, make the name be typed out — that is the case
     * where a wrong --database costs real work.
     */
    private function confirmDestruction(Target $target): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $live = config('database.connections.' . config('database.default') . '.database');

        if ($target->database === $live) {
            $this->newLine();
            $this->warn("{$target->database} is the database this app is configured to use.");
            $this->line('This will overwrite it.');

            return $this->ask('Type the database name to confirm') === $target->database;
        }

        return $this->confirm("Restore into {$target->describe()}?", false);
    }

    /**
     * @return array{differences: array<int, array<int, mixed>>, pending: array<string>}
     */
    private function reconcile(ConsistencyChecker $checker, Target $target, ?Manifest $manifest): array
    {
        $this->newLine();
        $diff = [];

        if ($manifest === null) {
            $this->line('No manifest — skipping row-count reconciliation.');
        } else {
            $this->line('Reconciling row counts…');
            $diff = $checker->reconcile($manifest, $checker->rowCounts($target));

            if ($diff === []) {
                $this->info('All ' . count($manifest->rowCounts) . ' tables match the manifest.');
            } else {
                $this->warn(count($diff) . ' table(s) differ from the manifest:');
                $this->table(['Table', 'In dump', 'Restored'], $diff);
            }
        }

        $pending = $checker->pendingMigrations($target);

        if ($pending === []) {
            $this->info('Schema is up to date with database/migrations.');

            return ['differences' => $diff, 'pending' => []];
        }

        $this->warn(count($pending) . ' pending migration(s) — run `php artisan migrate`:');

        foreach (array_slice($pending, 0, 10) as $migration) {
            $this->line('  ' . $migration);
        }

        if (count($pending) > 10) {
            $this->line('  … and ' . (count($pending) - 10) . ' more');
        }

        return ['differences' => $diff, 'pending' => $pending];
    }

    /** @param array<Finding> $findings */
    private function printFindings(array $findings): int
    {
        foreach ($findings as $finding) {
            if ($finding->isFail()) {
                $this->error('[fail] ' . $finding->message);

                if ($finding->hint !== null) {
                    $this->line('       ' . $finding->hint);
                }
            } elseif ($finding->isWarn()) {
                $this->warn('[warn] ' . $finding->message);

                if ($finding->hint !== null) {
                    $this->line('       ' . $finding->hint);
                }
            }
        }

        if (EnvironmentReport::hasFailure($findings)) {
            $this->newLine();
            $this->error('Refusing to continue. Run `php artisan pg:info` for the full report.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
