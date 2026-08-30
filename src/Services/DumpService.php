<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\BinaryNotFoundException;
use SergeyBruhin\PostgresTools\Exceptions\DumpFailedException;
use Symfony\Component\Process\Process;

/** Builds and runs pg_dump. */
final class DumpService
{
    public function __construct(
        private readonly Config        $config,
        private readonly BinaryLocator $binaries,
        private readonly PgEnvironment $environment,
    ) {}

    /**
     * The argv pg_dump would be invoked with. Exposed so --dry-run can print exactly what
     * is about to run; it carries no credentials, those travel via PGPASSFILE.
     *
     * @return array<string>
     *
     * @throws BinaryNotFoundException
     */
    public function command(Target $target, DumpOptions $options, string $destination): array
    {
        $argv = [
            $this->binaries->require('pg_dump'),
            '--host=' . $target->host,
            '--port=' . $target->port,
            '--username=' . $target->username,
            '--dbname=' . $target->database,
            '--no-password',
            '--format=' . $options->formatFlag(),
            '--no-owner',
            '--no-privileges',
            '--file=' . $destination,
        ];

        if ($options->format === DumpOptions::FORMAT_CUSTOM) {
            $argv[] = '--compress=' . $options->compress;
        }

        if ($options->schemaOnly) {
            $argv[] = '--schema-only';
        }

        if ($options->dataOnly) {
            $argv[] = '--data-only';
        }

        foreach ($options->onlyTables as $pattern) {
            $argv[] = '--table=' . $pattern;
        }

        foreach ($options->excludeTables as $pattern) {
            $argv[] = '--exclude-table=' . $pattern;
        }

        foreach ($options->excludeTableData as $pattern) {
            $argv[] = '--exclude-table-data=' . $pattern;
        }

        return $argv;
    }

    /**
     * Dump to $destination, returning the byte size of the finished file.
     *
     * Writes to <destination>.part and renames only on success, so an interrupted run can
     * never leave something that looks like a usable backup.
     *
     * @param  callable(string): void|null  $onOutput  receives pg_dump's stderr as it arrives
     *
     * @throws BinaryNotFoundException|DumpFailedException
     */
    public function dump(Target $target, DumpOptions $options, string $destination, ?callable $onOutput = null): int
    {
        $partial = $destination . '.part';

        @unlink($partial);

        $argv    = $this->command($target, $options, $partial);
        $timeout = (int) $this->config->get('postgres-tools.timeout', 3600);

        $result = $this->environment->run($target, function (array $env) use ($argv, $timeout, $onOutput) {
            $process = new Process($argv, null, $env);
            $process->setTimeout($timeout > 0 ? $timeout : null);

            $process->run(function (string $type, string $buffer) use ($onOutput): void {
                if ($onOutput !== null && trim($buffer) !== '') {
                    $onOutput(trim($buffer));
                }
            });

            return $process;
        });

        if (!$result->isSuccessful()) {
            @unlink($partial);

            throw new DumpFailedException(
                'pg_dump exited with code ' . $result->getExitCode() . ':' . PHP_EOL
                . trim($result->getErrorOutput() ?: $result->getOutput())
            );
        }

        if (!is_file($partial) || filesize($partial) === 0) {
            @unlink($partial);

            throw new DumpFailedException('pg_dump reported success but produced no output.');
        }

        if (!rename($partial, $destination)) {
            @unlink($partial);

            throw new DumpFailedException("Unable to move the finished dump into place at {$destination}.");
        }

        clearstatcache(true, $destination);

        return (int) filesize($destination);
    }
}
