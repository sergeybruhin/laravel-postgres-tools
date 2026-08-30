<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use Throwable;

/** Builds, writes and reads the sidecar <dump>.json. */
final class ManifestWriter
{
    private const SNAPSHOT_CONNECTION = 'pgtools_snapshot';

    public function __construct(
        private readonly Application     $app,
        private readonly DatabaseManager $db,
        private readonly TargetResolver  $resolver,
        private readonly BinaryLocator   $binaries,
    ) {}

    /**
     * Exact row counts per table in the public schema, captured before the dump starts.
     *
     * Tables whose data is excluded are recorded as 0 rather than counted: counting
     * telescope_entries can take longer than the dump itself, and the restored value is
     * going to be 0 regardless.
     *
     * @return array<string, int>
     */
    public function rowCounts(Target $target, DumpOptions $options): array
    {
        $name       = $this->resolver->registerRuntimeConnection($target, self::SNAPSHOT_CONNECTION);
        $connection = $this->db->connection($name);

        try {
            $tables = $connection->select(
                "select tablename from pg_tables where schemaname = 'public' order by tablename"
            );

            $counts = [];

            foreach ($tables as $row) {
                $table = (string) $row->tablename;

                if ($options->onlyTables !== [] && !TablePattern::matchesAny($table, $options->onlyTables)) {
                    continue;
                }

                if (TablePattern::matchesAny($table, $options->excludeTables)) {
                    continue;
                }

                if ($options->schemaOnly || TablePattern::matchesAny($table, $options->excludeTableData)) {
                    $counts[$table] = 0;
                    continue;
                }

                $counts[$table] = (int) $connection->selectOne(
                    'select count(*) as c from ' . $connection->getQueryGrammar()->wrapTable($table)
                )->c;
            }

            return $counts;
        } finally {
            $this->db->purge($name);
        }
    }

    /**
     * @return array{latest: ?string, count: int}
     */
    public function migrationState(Target $target): array
    {
        $name       = $this->resolver->registerRuntimeConnection($target, self::SNAPSHOT_CONNECTION);
        $connection = $this->db->connection($name);

        try {
            if (!$connection->getSchemaBuilder()->hasTable('migrations')) {
                return ['latest' => null, 'count' => 0];
            }

            $latest = $connection->table('migrations')->orderByDesc('id')->value('migration');

            return [
                'latest' => $latest === null ? null : (string) $latest,
                'count'  => (int) $connection->table('migrations')->count(),
            ];
        } catch (Throwable) {
            return ['latest' => null, 'count' => 0];
        } finally {
            $this->db->purge($name);
        }
    }

    public function build(
        Target      $target,
        DumpOptions $options,
        string      $serverVersion,
        int         $bytes,
        string      $sha256,
        array       $rowCounts,
        array       $migrations,
    ): Manifest {
        return new Manifest(
            createdAt:         now()->toIso8601String(),
            appEnv:            (string) $this->app->environment(),
            appVersion:        $this->appVersion(),
            connection:        $target->connection,
            database:          $target->database,
            host:              $target->host,
            serverVersion:     $serverVersion,
            pgDumpVersion:     $this->binaries->probe('pg_dump'),
            format:            $options->format,
            compress:          $options->compress,
            bytes:             $bytes,
            sha256:            $sha256,
            excludedTables:    array_values($options->excludeTables),
            excludedTableData: array_values($options->excludeTableData),
            onlyTables:        array_values($options->onlyTables),
            schemaOnly:        $options->schemaOnly,
            dataOnly:          $options->dataOnly,
            latestMigration:   $migrations['latest'] ?? null,
            migrationCount:    (int) ($migrations['count'] ?? 0),
            rowCounts:         $rowCounts,
        );
    }

    /**
     * @throws PostgresToolsException
     */
    public function write(string $dumpPath, Manifest $manifest): string
    {
        $path = BackupFile::manifestPathFor($dumpPath);

        if (file_put_contents($path, $manifest->toJson()) === false) {
            throw new PostgresToolsException("Unable to write the manifest at {$path}.");
        }

        return $path;
    }

    /** Null when the dump has no sidecar, which is not fatal — just unverifiable. */
    public function read(string $dumpPath): ?Manifest
    {
        $path = BackupFile::manifestPathFor($dumpPath);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            return Manifest::fromJson($contents);
        } catch (PostgresToolsException) {
            return null;
        }
    }

    /** Short git SHA when the tree is a checkout, so a dump can be traced to a deploy. */
    private function appVersion(): ?string
    {
        $head = $this->app->basePath('.git/HEAD');

        if (!is_file($head)) {
            return null;
        }

        $contents = trim((string) file_get_contents($head));

        if (str_starts_with($contents, 'ref: ')) {
            $ref  = $this->app->basePath('.git/' . substr($contents, 5));
            $sha  = is_file($ref) ? trim((string) file_get_contents($ref)) : null;
        } else {
            $sha = $contents;
        }

        return $sha ? substr($sha, 0, 12) : null;
    }
}
