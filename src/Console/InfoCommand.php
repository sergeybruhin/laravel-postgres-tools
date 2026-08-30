<?php

namespace SergeyBruhin\PostgresTools\Console;

use Illuminate\Console\Command;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\BinaryLocator;
use SergeyBruhin\PostgresTools\Services\EnvironmentReport;
use SergeyBruhin\PostgresTools\Services\TargetResolver;

class InfoCommand extends Command
{
    protected $signature = 'pg:info
        {--connection= : Inspect one Laravel connection instead of every pgsql one}
        {--database=   : Override the database name within that connection}
        {--path=       : Backup directory to check (default: config postgres-tools.path)}
        {--strict      : Exit with a failure code on warnings as well as failures}';

    protected $description = 'Report Postgres server versions, client binaries, their compatibility and backup readiness';

    /** @var array<Finding> */
    private array $findings = [];

    public function handle(
        TargetResolver    $resolver,
        EnvironmentReport $report,
        BinaryLocator     $binaries,
        BackupRepository  $backups,
    ): int {
        $connections = $this->option('connection')
            ? [(string) $this->option('connection')]
            : $resolver->pgsqlConnectionNames();

        if ($connections === []) {
            $this->error('No pgsql connections are defined in config/database.php.');

            return self::FAILURE;
        }

        $servers = [];

        foreach ($connections as $name) {
            try {
                $target = $resolver->resolve($name, $this->option('database'));
            } catch (PostgresToolsException $e) {
                $this->findings[] = Finding::fail($e->getMessage());
                continue;
            }

            $servers[$name] = $report->server($target) + ['target' => $target];
        }

        $this->serverSection($report, $servers);
        $this->binarySection($binaries, $servers);
        $this->compatibilitySection($report, $binaries, $servers);
        $this->storageSection($report, $backups, $servers);

        return $this->findingsSection();
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function serverSection(EnvironmentReport $report, array $servers): void
    {
        $rows = [];

        foreach ($servers as $name => $server) {
            $target = $server['target'];

            if (!$server['reachable']) {
                $this->findings[] = Finding::fail(
                    "Connection [{$name}] at {$target->describe()} is unreachable: {$server['error']}"
                );
            } elseif (($unsupported = $report->supportFinding($server['major'], $server['version'])) !== null) {
                $this->findings[] = $unsupported;
            } elseif (!$server['exists']) {
                $this->findings[] = Finding::warn(
                    "Database [{$target->database}] does not exist on {$target->host}:{$target->port}.",
                    'The server itself answered, so a restore can create it — but there is nothing to back up.'
                );
            }

            $rows[] = [
                $name,
                $target->describe(),
                $server['reachable'] ? $server['version'] : '—',
                $server['major'] !== null ? (string) $server['major'] : '—',
                $server['size'] !== null ? BackupFile::formatBytes($server['size']) : '—',
                match (true) {
                    !$server['reachable'] => 'NO',
                    !$server['exists']    => 'server only',
                    default               => 'yes',
                },
            ];
        }

        $this->newLine();
        $this->table(['Connection', 'Endpoint', 'Version', 'Major', 'Size', 'Reachable'], $rows);
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function binarySection(BinaryLocator $binaries, array $servers): void
    {
        $package = $this->recommendedPackage($servers);
        $rows    = [];

        foreach (['pg_dump', 'pg_restore', 'psql'] as $binary) {
            $version = $binaries->probe($binary);
            $path    = $binaries->path($binary);

            if ($version !== null) {
                $rows[] = [$binary, $version, (string) $path];
                continue;
            }

            $note = $package !== null ? "install {$package}" : 'not installed';

            // psql is only needed to load plain-format dumps; a custom-format round trip
            // never touches it, so its absence is not worth failing over.
            if ($binary === 'psql') {
                $rows[] = [$binary, '—', 'optional — only for --format=plain restores'];
                continue;
            }

            $rows[] = [$binary, '—', $note];
        }

        $this->table(['Binary', 'Version', 'Path'], $rows);
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function compatibilitySection(EnvironmentReport $report, BinaryLocator $binaries, array $servers): void
    {
        $clientMajor = BinaryLocator::major($binaries->probe('pg_dump'));
        $rows        = [];

        foreach ($servers as $name => $server) {
            if (!$server['reachable']) {
                continue;
            }

            $finding          = $report->compatibility($clientMajor, $server['major']);
            $this->findings[] = $finding;

            $rows[] = [$name, strtoupper($finding->level), $finding->message];
        }

        $package = $this->recommendedPackage($servers);

        if ($package !== null) {
            $rows[] = ['—', 'CLIENT', "Recommended package: {$package}"];
        }

        if ($rows !== []) {
            $this->table(['Connection', 'Verdict', 'Detail'], $rows);
        }
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function storageSection(EnvironmentReport $report, BackupRepository $backups, array $servers): void
    {
        $path    = $backups->path($this->option('path'));
        $largest = 0;

        foreach ($servers as $server) {
            $largest = max($largest, (int) ($server['size'] ?? 0));
        }

        $storage = $report->storage($path, $largest ?: null);
        $dumps   = $backups->all($path);

        if (!$storage['writable']) {
            $this->findings[] = Finding::fail("Backup directory is not writable: {$path}");
        } elseif (!$storage['exists']) {
            $this->findings[] = Finding::warn(
                "Backup directory does not exist yet: {$path}",
                'It will be created on the first pg:backup run.'
            );
        }

        if ($storage['ratio'] !== null && $storage['ratio'] < 2.0) {
            $this->findings[] = Finding::warn(sprintf(
                'Free space is only %.1fx the largest database; a dump may not fit.',
                $storage['ratio']
            ));
        }

        $this->table(['Storage', ''], [
            ['Backup path', $path . ($storage['exists'] ? '' : '  (missing)')],
            ['Writable', $storage['writable'] ? 'yes' : 'NO'],
            ['Free space', $storage['free'] !== null ? BackupFile::formatBytes($storage['free']) : '—'],
            ['Headroom', $storage['ratio'] !== null ? sprintf('%.1fx the database size', $storage['ratio']) : '—'],
            ['Dumps present', $dumps === [] ? '—' : count($dumps) . ' (newest: ' . $dumps[0]->name() . ')'],
        ]);
    }

    private function findingsSection(): int
    {
        $problems = array_values(array_filter(
            $this->findings,
            static fn (Finding $f) => $f->isFail() || $f->isWarn()
        ));

        if ($problems === []) {
            $this->info('No problems found — this environment can take and load dumps.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<comment>Findings</comment>');

        foreach ($problems as $finding) {
            $tag = $finding->isFail() ? '<fg=red>[fail]</>' : '<fg=yellow>[warn]</>';

            $this->line("  {$tag} {$finding->message}");

            if ($finding->hint !== null) {
                $this->line("         {$finding->hint}");
            }
        }

        $this->newLine();

        if (EnvironmentReport::hasFailure($problems)) {
            return self::FAILURE;
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, array<string, mixed>> $servers */
    private function recommendedPackage(array $servers): ?string
    {
        $majors = [];

        foreach ($servers as $server) {
            if ($server['major'] !== null) {
                $majors[] = (int) $server['major'];
            }
        }

        return $majors === [] ? null : 'postgresql-client-' . max($majors);
    }
}
