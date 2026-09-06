<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Data\Target;
use Throwable;

/**
 * Read-only probe of everything a dump or restore depends on: the server, the client
 * binaries, whether their major versions agree, and whether the destination has room.
 *
 * pg:info prints it, and pg:backup / pg:restore run it as a pre-flight, so the three can
 * never disagree about whether this machine is able to do the job.
 */
final class EnvironmentReport
{
    /**
     * Servers older than this are refused rather than quietly attempted. The package is
     * only exercised against 14 and up, and a failure here is far cheaper than one
     * discovered halfway through restoring a production dump.
     */
    public const MINIMUM_SERVER_MAJOR = 14;

    private const PROBE_CONNECTION = 'pgtools_probe';

    public function __construct(
        private readonly Config          $config,
        private readonly DatabaseManager $db,
        private readonly TargetResolver  $resolver,
        private readonly BinaryLocator   $binaries,
    ) {}

    /**
     * Probe the server behind a target.
     *
     * A target database that does not exist yet is a normal state, not an error: it is
     * exactly what `pg:restore --database=scratch --drop` is about to create. So when the
     * database is missing we fall back to the "postgres" maintenance database, which still
     * gives us the server version — and therefore the client package to recommend.
     *
     * @return array{reachable: bool, exists: bool, version: ?string, major: ?int, size: ?int, error: ?string}
     */
    public function server(Target $target): array
    {
        $direct = $this->probe($target);

        if ($direct['reachable']) {
            return $direct + ['exists' => true];
        }

        $fallback = $this->probe($target->withDatabase('postgres'));

        if (!$fallback['reachable']) {
            return $direct + ['exists' => false];
        }

        return [
            'reachable' => true,
            'exists'    => false,
            'version'   => $fallback['version'],
            'major'     => $fallback['major'],
            'size'      => null,
            'error'     => null,
        ];
    }

    /**
     * @return array{reachable: bool, version: ?string, major: ?int, size: ?int, error: ?string}
     */
    private function probe(Target $target): array
    {
        $name = $this->resolver->registerRuntimeConnection($target, self::PROBE_CONNECTION);

        try {
            $connection = $this->db->connection($name);

            $version = (string) $connection->selectOne("select current_setting('server_version') as v")->v;
            $size    = (int) $connection->selectOne('select pg_database_size(current_database()) as s')->s;

            return [
                'reachable' => true,
                'version'   => $version,
                'major'     => BinaryLocator::major($version),
                'size'      => $size,
                'error'     => null,
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'version'   => null,
                'major'     => null,
                'size'      => null,
                'error'     => $e->getMessage(),
            ];
        } finally {
            $this->db->purge($name);
        }
    }

    /**
     * @return array<string, ?string> binary name => version, null when not installed
     */
    public function binaryVersions(): array
    {
        return [
            'pg_dump'    => $this->binaries->probe('pg_dump'),
            'pg_restore' => $this->binaries->probe('pg_restore'),
            'psql'       => $this->binaries->probe('psql'),
        ];
    }

    /** The client package to install, derived from the server actually reached. */
    public function recommendedClientPackage(?int $serverMajor): ?string
    {
        return $serverMajor === null ? null : "postgresql-client-{$serverMajor}";
    }

    /**
     * The verdict that matters most. pg_dump refuses to dump from a newer server outright,
     * and a newer client produces archives an older server cannot load — which fails much
     * later, halfway through a restore, and is the mistake worth catching here.
     */
    public function compatibility(?int $clientMajor, ?int $serverMajor): Finding
    {
        if ($serverMajor === null) {
            return Finding::fail('Cannot judge client compatibility: the server is unreachable.');
        }

        $package = $this->recommendedClientPackage($serverMajor);

        if ($clientMajor === null) {
            return Finding::fail(
                'pg_dump is not installed, so nothing can be dumped or restored.',
                "Install {$package} on the host or image that runs artisan."
            );
        }

        if ($clientMajor < $serverMajor) {
            return Finding::fail(
                "pg_dump {$clientMajor} is older than the server ({$serverMajor}); it will refuse to run.",
                "Install {$package} instead."
            );
        }

        if ($clientMajor > $serverMajor) {
            return Finding::warn(
                "pg_dump {$clientMajor} is newer than the server ({$serverMajor}); dumps taken here "
                . "cannot be restored into a {$serverMajor} server.",
                "Pin {$package} so every environment shares one major version."
            );
        }

        return Finding::ok("pg_dump {$clientMajor} matches the server ({$serverMajor}).");
    }

    /**
     * Null when the server is supported, a failure otherwise.
     *
     * Refusing up front is far cheaper than discovering the incompatibility halfway
     * through restoring a production dump.
     */
    public function supportFinding(?int $major, ?string $version): ?Finding
    {
        if ($major === null || $major >= self::MINIMUM_SERVER_MAJOR) {
            return null;
        }

        return Finding::fail(
            "PostgreSQL {$version} is below the minimum supported major version ("
            . self::MINIMUM_SERVER_MAJOR . ').',
            'pg:backup and pg:restore will refuse to run against this server.'
        );
    }

    /**
     * @return array{path: string, exists: bool, writable: bool, free: ?int, ratio: ?float}
     */
    public function storage(string $path, ?int $databaseBytes = null): array
    {
        $exists = is_dir($path);

        // A directory we would create on demand still tells us nothing about free space,
        // so walk up to the nearest parent that does exist.
        $probe = $path;

        while (!is_dir($probe) && dirname($probe) !== $probe) {
            $probe = dirname($probe);
        }

        $free = is_dir($probe) ? @disk_free_space($probe) : false;
        $free = $free === false ? null : (int) $free;

        return [
            'path'     => $path,
            'exists'   => $exists,
            'writable' => is_dir($probe) && is_writable($probe),
            'free'     => $free,
            'ratio'    => ($free !== null && $databaseBytes) ? $free / $databaseBytes : null,
        ];
    }

    /**
     * Everything a command needs to decide whether to proceed.
     *
     * $binaryVersions overrides the local probe with versions discovered elsewhere — the
     * queue worker that actually runs pg_dump, say, when this is called from a web request
     * that itself has no client binaries and never will. Null probes locally, which is
     * always correct for pg:info/pg:backup/pg:restore since they run where the dump does.
     *
     * @param array<string, ?string>|null $binaryVersions
     * @return array<Finding>
     */
    public function findings(
        Target $target,
        string $path,
        bool   $requireBinaries = true,
        bool   $requireDatabase = true,
        ?array $binaryVersions = null,
    ): array {
        $findings = [];
        $server   = $this->server($target);

        if (!$server['reachable']) {
            $findings[] = Finding::fail(
                "Cannot reach {$target->describe()} on connection [{$target->connection}]: {$server['error']}"
            );

            return $findings;
        }

        if (($unsupported = $this->supportFinding($server['major'], $server['version'])) !== null) {
            $findings[] = $unsupported;
        }

        if (!$server['exists']) {
            // Nothing to dump from a database that is not there; but a restore is entitled
            // to create it, so this is only fatal for the reading side.
            $findings[] = $requireDatabase
                ? Finding::fail("Database [{$target->database}] does not exist on {$target->host}:{$target->port}.")
                : Finding::ok("Database [{$target->database}] does not exist yet and will be created.");
        } else {
            $findings[] = Finding::ok("Connected to {$target->describe()} (Postgres {$server['version']}).");
        }

        if ($requireBinaries) {
            $binaries = $binaryVersions ?? $this->binaryVersions();

            foreach (['pg_dump', 'pg_restore'] as $binary) {
                if (($binaries[$binary] ?? null) === null) {
                    $package = $this->recommendedClientPackage($server['major']);

                    $findings[] = Finding::fail(
                        "{$binary} not found in PATH or config('postgres-tools.binaries.{$binary}').",
                        "Install {$package} on the host or image that runs artisan."
                    );
                }
            }

            $findings[] = $this->compatibility(
                BinaryLocator::major($binaries['pg_dump'] ?? null),
                $server['major'],
            );
        }

        $storage = $this->storage($path, $server['size']);

        if (!$storage['writable']) {
            $findings[] = Finding::fail("Backup directory is not writable: {$path}");
        } elseif (!$storage['exists']) {
            $findings[] = Finding::warn("Backup directory does not exist yet: {$path}", 'It will be created on first use.');
        }

        // A dump is smaller than the live database, but not reliably so with low
        // compression, so warn below 2x rather than pretend to know the ratio.
        if ($storage['ratio'] !== null && $storage['ratio'] < 2.0) {
            $findings[] = Finding::warn(sprintf(
                'Free space is only %.1fx the database size; a dump may not fit.',
                $storage['ratio']
            ));
        }

        return $findings;
    }

    public static function hasFailure(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->isFail()) {
                return true;
            }
        }

        return false;
    }

    public static function hasWarning(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->isWarn()) {
                return true;
            }
        }

        return false;
    }
}
