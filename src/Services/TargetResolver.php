<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;

/**
 * Turns --connection / --database into a concrete endpoint.
 *
 * --connection picks the config/database.php block; --database swaps only the dbname
 * within it, leaving host and credentials alone. That is what lets a dump taken from
 * app_production be restored into app_scratch without editing any config.
 */
final class TargetResolver
{
    /** Name of the runtime connection used for DROP/CREATE DATABASE. */
    public const MAINTENANCE_CONNECTION = 'pgtools_maint';

    public function __construct(
        private readonly Config          $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @throws PostgresToolsException
     */
    public function resolve(?string $connection = null, ?string $database = null): Target
    {
        $name = $connection
            ?: $this->config->get('postgres-tools.connection')
            ?: $this->config->get('database.default');

        $conf = $this->config->get("database.connections.{$name}");

        if (!is_array($conf)) {
            throw new PostgresToolsException(
                "Connection [{$name}] is not defined in config/database.php."
            );
        }

        if (($conf['driver'] ?? null) !== 'pgsql') {
            throw new PostgresToolsException(
                "Connection [{$name}] uses driver [" . ($conf['driver'] ?? 'none') . "]; only pgsql is supported."
            );
        }

        $resolved = $database ?: ($conf['database'] ?? null);

        if (!$resolved) {
            throw new PostgresToolsException(
                "Connection [{$name}] has no database name and none was given with --database."
            );
        }

        return new Target(
            connection: $name,
            host:       (string) ($conf['host'] ?? '127.0.0.1'),
            port:       (int) ($conf['port'] ?? 5432),
            database:   (string) $resolved,
            username:   (string) ($conf['username'] ?? ''),
            password:   (string) ($conf['password'] ?? ''),
            sslmode:    $conf['sslmode'] ?? null,
            searchPath: $conf['search_path'] ?? ($conf['schema'] ?? null),
        );
    }

    /** Every pgsql connection defined in config/database.php, for the readiness report. */
    public function pgsqlConnectionNames(): array
    {
        $names = [];

        foreach ((array) $this->config->get('database.connections', []) as $name => $conf) {
            if (is_array($conf) && ($conf['driver'] ?? null) === 'pgsql') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Register a runtime connection pointing at the target endpoint, so queries can run
     * against a database that has no entry in config/database.php.
     */
    public function registerRuntimeConnection(Target $target, string $name): string
    {
        $this->config->set("database.connections.{$name}", $target->toConnectionConfig());
        $this->db->purge($name);

        return $name;
    }

    /**
     * A connection to the "postgres" maintenance database on the same server. DROP DATABASE
     * cannot run from inside the database being dropped, and this keeps us off psql, which
     * the containers may not have.
     */
    public function maintenanceConnection(Target $target): string
    {
        return $this->registerRuntimeConnection(
            $target->withDatabase('postgres'),
            self::MAINTENANCE_CONNECTION,
        );
    }
}
