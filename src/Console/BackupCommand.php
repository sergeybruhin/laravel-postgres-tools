<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\DumpService;
use SergeyBruhin\PostgresTools\Services\EnvironmentReport;
use SergeyBruhin\PostgresTools\Services\ManifestWriter;
use SergeyBruhin\PostgresTools\Services\TargetResolver;

class BackupCommand extends Command
{
    protected $signature = 'pg:backup
        {--connection=          : Laravel connection to read from (default: config default)}
        {--database=            : Override the database name within that connection}
        {--path=                : Output directory (default: config postgres-tools.path)}
        {--name=                : Output filename (default: <db>-<Y-m-d_His>.<ext>)}
        {--format=              : custom or plain (default: config postgres-tools.format)}
        {--compress=            : Compression level 0-9 (default: config postgres-tools.compress)}
        {--schema-only          : Dump structure without data}
        {--data-only            : Dump data without structure}
        {--exclude-table=*      : Table pattern to omit entirely, schema included (repeatable)}
        {--exclude-table-data=* : Table pattern to keep but dump empty (repeatable)}
        {--only-table=*         : Restrict the dump to these table patterns (repeatable)}
        {--all                  : Ignore the configured exclusions and dump everything}
        {--keep=                : Keep only the N newest dumps (bare --keep uses the configured retention)}
        {--no-manifest          : Skip the sidecar .json manifest}
        {--dry-run              : Print the resolved pg_dump invocation and exit}
        {--force                : Skip interactive confirmation}';

    protected $description = 'Dump a Postgres database to a verifiable file with a sidecar manifest';

    public function handle(
        TargetResolver     $resolver,
        EnvironmentReport  $report,
        BackupRepository   $backups,
        DumpService        $dumps,
        ManifestWriter     $manifests,
        ConsistencyChecker $checker,
    ): int {
        try {
            $target  = $resolver->resolve($this->option('connection'), $this->option('database'));
            $options = $this->dumpOptions();
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $backups->path($this->option('path'));
        $file = $path . '/' . ($this->option('name') ?: $this->defaultName($target, $options));

        $findings = $report->findings($target, $path);
        $server   = $report->server($target);

        $this->summary($target, $options, $file, $server);

        if ($this->printFindings($findings) === self::FAILURE) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->dryRun($dumps, $target, $options, $file);

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Dump {$target->describe()} to {$file}?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        try {
            $backups->ensureDirectory($path);

            // Counted before the dump starts so the manifest describes the same snapshot
            // pg_dump is about to take, not the state afterwards.
            $this->line('Counting rows…');
            $rowCounts  = $manifests->rowCounts($target, $options);
            $migrations = $manifests->migrationState($target);

            $this->line('Running pg_dump…');
            $started = microtime(true);

            $bytes = $dumps->dump($target, $options, $file, function (string $line): void {
                $this->line('  ' . $line);
            });

            $elapsed = round(microtime(true) - $started, 1);
            $this->info("Wrote {$file} (" . BackupFile::formatBytes($bytes) . ") in {$elapsed}s.");

            $this->verify($checker, $options, $file);

            if (!$this->option('no-manifest')) {
                $manifest = $manifests->build(
                    target:        $target,
                    options:       $options,
                    serverVersion: (string) ($server['version'] ?? ''),
                    bytes:         $bytes,
                    sha256:        $checker->checksum($file),
                    rowCounts:     $rowCounts,
                    migrations:    $migrations,
                );

                $manifests->write($file, $manifest);
                $this->line('Manifest written: ' . basename($file) . '.json');
            }

            $this->prune($backups, $path);
        } catch (PostgresToolsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * CLI flags win over config; --all drops the configured exclusions entirely, which is
     * the only way to get Telescope's rows into a dump.
     *
     * @throws PostgresToolsException
     */
    private function dumpOptions(): DumpOptions
    {
        $excludeTables    = (array) $this->option('exclude-table');
        $excludeTableData = (array) $this->option('exclude-table-data');

        if (!$this->option('all')) {
            $excludeTables    = $excludeTables ?: (array) config('postgres-tools.exclude_tables', []);
            $excludeTableData = $excludeTableData ?: (array) config('postgres-tools.exclude_table_data', []);
        }

        $onlyTables = (array) $this->option('only-table');

        if ($onlyTables !== []) {
            $excludeTables    = [];
            $excludeTableData = [];
        }

        return new DumpOptions(
            format:           (string) ($this->option('format') ?: config('postgres-tools.format', 'custom')),
            compress:         (int) ($this->option('compress') ?? config('postgres-tools.compress', 6)),
            schemaOnly:       (bool) $this->option('schema-only'),
            dataOnly:         (bool) $this->option('data-only'),
            excludeTables:    $excludeTables,
            excludeTableData: $excludeTableData,
            onlyTables:       $onlyTables,
        );
    }

    private function defaultName(Target $target, DumpOptions $options): string
    {
        return sprintf('%s-%s.%s', $target->database, now()->format('Y-m-d_His'), $options->extension());
    }

    /** @param array<string, mixed> $server */
    private function summary(Target $target, DumpOptions $options, string $file, array $server): void
    {
        $this->newLine();
        $this->table(['Backup', ''], [
            ['Connection', $target->connection],
            ['Source', $target->describe()],
            ['Server', $server['version'] ?? '—'],
            ['Size', $server['size'] !== null ? BackupFile::formatBytes((int) $server['size']) : '—'],
            ['Destination', $file],
            ['Format', $options->format . ($options->format === DumpOptions::FORMAT_CUSTOM ? ", compress {$options->compress}" : '')],
            ['Scope', $this->scopeLabel($options)],
            ['Excluded', $options->excludeTables === [] ? '—' : implode(', ', $options->excludeTables)],
            ['Emptied', $options->excludeTableData === [] ? '—' : implode(', ', $options->excludeTableData)],
            ['Restricted to', $options->onlyTables === [] ? '—' : implode(', ', $options->onlyTables)],
        ]);
    }

    private function scopeLabel(DumpOptions $options): string
    {
        return match (true) {
            $options->schemaOnly => 'schema only',
            $options->dataOnly   => 'data only',
            default              => 'schema and data',
        };
    }

    private function dryRun(DumpService $dumps, Target $target, DumpOptions $options, string $file): void
    {
        try {
            $argv = $dumps->command($target, $options, $file . '.part');
        } catch (PostgresToolsException $e) {
            $this->warn('Cannot build the pg_dump invocation:');
            $this->line('  ' . str_replace(PHP_EOL, PHP_EOL . '  ', $e->getMessage()));

            return;
        }

        $this->line('<comment>Would run</comment> (credentials travel via PGPASSFILE, not argv):');
        $this->line('  ' . implode(" \\\n    ", $argv));
        $this->newLine();
        $this->info('Dry run — nothing was written.');
    }

    private function verify(ConsistencyChecker $checker, DumpOptions $options, string $file): void
    {
        // A plain-SQL dump has no table of contents to read back, so the checksum in the
        // manifest is the only integrity signal it gets.
        if ($options->format !== DumpOptions::FORMAT_CUSTOM) {
            return;
        }

        $entries = $checker->verifyArchive($file);
        $this->info("Archive verified: {$entries} entries readable by pg_restore.");
    }

    private function prune(BackupRepository $backups, string $path): void
    {
        // --keep with no value falls back to the configured retention, so a deploy script
        // can just pass the flag; without the flag at all nothing is ever deleted.
        if (!$this->input->hasParameterOption('--keep')) {
            return;
        }

        $keep = (int) ($this->option('keep') ?: config('postgres-tools.keep', 0));

        if ($keep < 1) {
            $this->line('Rotation: disabled (keep is 0).');

            return;
        }

        $deleted = $backups->prune($path, $keep);

        if ($deleted === []) {
            $this->line("Rotation: nothing to remove, {$keep} kept.");

            return;
        }

        $this->line('Rotation: removed ' . count($deleted) . ' older dump(s) — ' . implode(', ', $deleted));
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
