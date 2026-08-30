<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\BinaryNotFoundException;
use SergeyBruhin\PostgresTools\Exceptions\RestoreFailedException;
use Symfony\Component\Process\Process;

/** Recreates a database and loads an archive into it. */
final class RestoreService
{
    public function __construct(
        private readonly Config          $config,
        private readonly DatabaseManager $db,
        private readonly BinaryLocator   $binaries,
        private readonly PgEnvironment   $environment,
        private readonly TargetResolver  $resolver,
    ) {}

    /**
     * DROP and CREATE the target database over PDO, via the "postgres" maintenance
     * database. Doing it in PHP rather than psql means the containers only ever need
     * pg_dump and pg_restore.
     */
    public function recreateDatabase(Target $target): void
    {
        $name       = $this->resolver->maintenanceConnection($target);
        $connection = $this->db->connection($name);
        $quoted     = '"' . str_replace('"', '""', $target->database) . '"';

        try {
            // DROP DATABASE fails outright while any session is attached, and a stray
            // Horizon worker or an open psql is enough to block it.
            $connection->select(
                'select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()',
                [$target->database]
            );

            $connection->statement("DROP DATABASE IF EXISTS {$quoted}");
            $connection->statement("CREATE DATABASE {$quoted}");
        } finally {
            $this->db->purge($name);
        }
    }

    public function createDatabaseIfMissing(Target $target): bool
    {
        $name       = $this->resolver->maintenanceConnection($target);
        $connection = $this->db->connection($name);
        $quoted     = '"' . str_replace('"', '""', $target->database) . '"';

        try {
            $exists = $connection->selectOne(
                'select 1 as e from pg_database where datname = ?',
                [$target->database]
            );

            if ($exists !== null) {
                return false;
            }

            $connection->statement("CREATE DATABASE {$quoted}");

            return true;
        } finally {
            $this->db->purge($name);
        }
    }

    /**
     * @return array<string>
     *
     * @throws BinaryNotFoundException
     */
    public function command(Target $target, string $file, int $jobs = 1, bool $clean = false): array
    {
        $argv = [
            $this->binaries->require('pg_restore'),
            '--host=' . $target->host,
            '--port=' . $target->port,
            '--username=' . $target->username,
            '--dbname=' . $target->database,
            '--no-password',
            '--no-owner',
            '--no-privileges',
        ];

        if ($clean) {
            $argv[] = '--clean';
            $argv[] = '--if-exists';
        }

        if ($jobs > 1) {
            $argv[] = '--jobs=' . $jobs;
        }

        $argv[] = $file;

        return $argv;
    }

    /**
     * Load a plain-SQL dump by piping it through psql.
     *
     * pg_restore cannot read plain SQL, so this is a separate path — and psql is the one
     * binary the containers may legitimately lack, hence the explicit require().
     *
     * @return array<string>
     *
     * @throws BinaryNotFoundException|RestoreFailedException
     */
    public function restorePlain(Target $target, string $file, ?callable $onOutput = null): array
    {
        $argv = [
            $this->binaries->require('psql'),
            '--host=' . $target->host,
            '--port=' . $target->port,
            '--username=' . $target->username,
            '--dbname=' . $target->database,
            '--no-password',
            '--quiet',
            // Without this psql reports success even when a statement failed part-way.
            '--set=ON_ERROR_STOP=1',
            // A plain dump ends in setval() calls whose result rows psql would otherwise
            // print; errors and notices still go to stderr.
            '--output=/dev/null',
            '--file=' . $file,
        ];

        $timeout = (int) $this->config->get('postgres-tools.timeout', 3600);

        $process = $this->environment->run($target, function (array $env) use ($argv, $timeout, $onOutput) {
            $process = new Process($argv, null, $env);
            $process->setTimeout($timeout > 0 ? $timeout : null);

            $process->run(function (string $type, string $buffer) use ($onOutput): void {
                if ($onOutput !== null && trim($buffer) !== '') {
                    $onOutput(trim($buffer));
                }
            });

            return $process;
        });

        if (!$process->isSuccessful()) {
            throw new RestoreFailedException(
                'psql exited with code ' . $process->getExitCode() . ':' . PHP_EOL
                . trim($process->getErrorOutput())
            );
        }

        return $this->warningsIn(trim($process->getErrorOutput()));
    }

    /**
     * Load a custom-format archive.
     *
     * @param  callable(string): void|null  $onOutput
     * @return array<string>  non-fatal warnings pg_restore emitted
     *
     * @throws BinaryNotFoundException|RestoreFailedException
     */
    public function restore(
        Target    $target,
        string    $file,
        int       $jobs = 1,
        bool      $clean = false,
        ?callable $onOutput = null,
    ): array {
        $argv    = $this->command($target, $file, $jobs, $clean);
        $timeout = (int) $this->config->get('postgres-tools.timeout', 3600);

        $process = $this->environment->run($target, function (array $env) use ($argv, $timeout, $onOutput) {
            $process = new Process($argv, null, $env);
            $process->setTimeout($timeout > 0 ? $timeout : null);

            $process->run(function (string $type, string $buffer) use ($onOutput): void {
                if ($onOutput !== null && trim($buffer) !== '') {
                    $onOutput(trim($buffer));
                }
            });

            return $process;
        });

        $stderr   = trim($process->getErrorOutput());
        $warnings = $this->warningsIn($stderr);

        if ($process->isSuccessful()) {
            return $warnings;
        }

        // pg_restore exits 1 when it only emitted warnings — typically "no privileges could
        // be revoked" noise from --no-owner. Treat that as success and surface the lines;
        // anything containing a real error is a failure.
        if ($process->getExitCode() === 1 && $warnings !== [] && !$this->hasErrors($stderr)) {
            return $warnings;
        }

        throw new RestoreFailedException(
            'pg_restore exited with code ' . $process->getExitCode() . ':' . PHP_EOL . $stderr
        );
    }

    /** @return array<string> */
    private function warningsIn(string $stderr): array
    {
        $warnings = [];

        foreach (explode("\n", $stderr) as $line) {
            $line = trim($line);

            if ($line !== '' && stripos($line, 'warning') !== false) {
                $warnings[] = $line;
            }
        }

        return $warnings;
    }

    private function hasErrors(string $stderr): bool
    {
        foreach (explode("\n", $stderr) as $line) {
            $line = trim($line);

            if ($line === '' || stripos($line, 'warning') !== false) {
                continue;
            }

            if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
                return true;
            }
        }

        return false;
    }
}
