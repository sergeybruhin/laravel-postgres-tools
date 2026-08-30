<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\ChecksumMismatchException;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The checks that turn "pg_dump exited 0" into "this dump is actually restorable", and
 * "pg_restore exited 0" into "the data really arrived".
 */
final class ConsistencyChecker
{
    private const VERIFY_CONNECTION = 'pgtools_verify';

    public function __construct(
        private readonly Application     $app,
        private readonly DatabaseManager $db,
        private readonly TargetResolver  $resolver,
        private readonly BinaryLocator   $binaries,
    ) {}

    public function checksum(string $path): string
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new PostgresToolsException("Unable to read {$path} to compute a checksum.");
        }

        return $hash;
    }

    /**
     * @throws ChecksumMismatchException
     */
    public function verifyChecksum(string $path, Manifest $manifest): void
    {
        if ($manifest->sha256 === '') {
            return;
        }

        $actual = $this->checksum($path);

        if (!hash_equals($manifest->sha256, $actual)) {
            throw new ChecksumMismatchException(
                "Checksum mismatch for {$path}." . PHP_EOL
                . "  expected {$manifest->sha256}" . PHP_EOL
                . "  actual   {$actual}" . PHP_EOL
                . 'The dump is corrupt or truncated. Re-copy it, or pass --skip-verify to override.'
            );
        }
    }

    /**
     * Ask pg_restore to list the archive's table of contents. This is the cheapest proof
     * that the file is a complete, readable archive rather than a truncated copy that
     * happens to have the right first bytes.
     *
     * @return int number of entries in the archive
     */
    public function verifyArchive(string $path): int
    {
        $process = new Process([$this->binaries->require('pg_restore'), '--list', $path]);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new PostgresToolsException(
                'pg_restore could not read the archive:' . PHP_EOL . trim($process->getErrorOutput())
            );
        }

        $entries = 0;

        foreach (explode("\n", $process->getOutput()) as $line) {
            if ($line !== '' && !str_starts_with(ltrim($line), ';')) {
                $entries++;
            }
        }

        return $entries;
    }

    /** @return array<string, int> */
    public function rowCounts(Target $target): array
    {
        $name       = $this->resolver->registerRuntimeConnection($target, self::VERIFY_CONNECTION);
        $connection = $this->db->connection($name);

        try {
            $counts = [];

            foreach ($connection->select("select tablename from pg_tables where schemaname = 'public'") as $row) {
                $table = (string) $row->tablename;

                $counts[$table] = (int) $connection->selectOne(
                    'select count(*) as c from ' . $connection->getQueryGrammar()->wrapTable($table)
                )->c;
            }

            ksort($counts);

            return $counts;
        } finally {
            $this->db->purge($name);
        }
    }

    /** Does the target already contain tables? Used to warn before an unguarded restore. */
    public function tableCount(Target $target): int
    {
        $name = $this->resolver->registerRuntimeConnection($target, self::VERIFY_CONNECTION);

        try {
            return (int) $this->db->connection($name)->selectOne(
                "select count(*) as c from pg_tables where schemaname = 'public'"
            )->c;
        } catch (Throwable) {
            return 0;
        } finally {
            $this->db->purge($name);
        }
    }

    public function databaseExists(Target $target): bool
    {
        $name = $this->resolver->maintenanceConnection($target);

        try {
            return $this->db->connection($name)
                ->selectOne('select 1 as e from pg_database where datname = ?', [$target->database]) !== null;
        } finally {
            $this->db->purge($name);
        }
    }

    /**
     * Compare what the dump claimed to hold against what the target now holds.
     *
     * @param  array<string, int>  $actual
     * @return array<int, array{0: string, 1: string, 2: string}>  rows of [table, expected, actual]
     */
    public function reconcile(Manifest $manifest, array $actual): array
    {
        $rows = [];

        foreach ($manifest->rowCounts as $table => $expected) {
            $found = $actual[$table] ?? null;

            if ($found === null) {
                $rows[] = [$table, number_format((int) $expected), 'missing'];
                continue;
            }

            if ((int) $found !== (int) $expected) {
                $rows[] = [$table, number_format((int) $expected), number_format($found)];
            }
        }

        foreach ($actual as $table => $found) {
            if (!array_key_exists($table, $manifest->rowCounts)) {
                $rows[] = [$table, 'not in dump', number_format($found)];
            }
        }

        return $rows;
    }

    /**
     * Migrations that exist in database/migrations but are absent from the restored
     * database. This is the check that catches the usual prod-to-local surprise: the dump
     * predates local work, and nothing tells you until something breaks at runtime.
     *
     * @return array<string>
     */
    public function pendingMigrations(Target $target): array
    {
        $name       = $this->resolver->registerRuntimeConnection($target, self::VERIFY_CONNECTION);
        $connection = $this->db->connection($name);

        try {
            if (!$connection->getSchemaBuilder()->hasTable('migrations')) {
                return [];
            }

            $ran = $connection->table('migrations')->pluck('migration')->all();
            $ran = array_flip(array_map('strval', $ran));

            $pending = [];

            foreach (glob($this->app->databasePath('migrations/*.php')) ?: [] as $file) {
                $migration = basename($file, '.php');

                if (!isset($ran[$migration])) {
                    $pending[] = $migration;
                }
            }

            sort($pending);

            return $pending;
        } catch (Throwable) {
            return [];
        } finally {
            $this->db->purge($name);
        }
    }

    /**
     * Restoring an archive produced by a newer pg_dump into an older server is not
     * supported and tends to fail deep into the restore, so refuse up front.
     */
    public function versionFinding(Manifest $manifest, ?int $targetMajor): Finding
    {
        $dumpMajor = $manifest->serverMajor();

        if ($dumpMajor === null || $targetMajor === null) {
            return Finding::warn('Could not compare Postgres versions between the dump and the target.');
        }

        if ($targetMajor < $dumpMajor) {
            return Finding::fail(
                "The dump came from Postgres {$dumpMajor} but the target is {$targetMajor}; "
                . 'restoring into an older major version is not supported.',
                "Bump the target server to {$dumpMajor}, or take the dump from a {$targetMajor} server."
            );
        }

        if ($targetMajor > $dumpMajor) {
            return Finding::warn(
                "The dump came from Postgres {$dumpMajor} and the target is {$targetMajor}; "
                . 'an upgrade restore usually works but is not identical to production.'
            );
        }

        return Finding::ok("Dump and target are both Postgres {$dumpMajor}.");
    }
}
