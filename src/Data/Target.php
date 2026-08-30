<?php

namespace SergeyBruhin\PostgresTools\Data;

/**
 * A resolved Postgres endpoint: a Laravel connection block with the database name
 * optionally swapped out, so a dump taken from one database can be restored into another
 * without touching config/database.php.
 */
final class Target
{
    public function __construct(
        public readonly string  $connection,
        public readonly string  $host,
        public readonly int     $port,
        public readonly string  $database,
        public readonly string  $username,
        public readonly string  $password,
        public readonly ?string $sslmode = null,
        public readonly ?string $searchPath = null,
    ) {}

    /** Same endpoint, different database. Used to reach the maintenance database. */
    public function withDatabase(string $database): self
    {
        return new self(
            $this->connection,
            $this->host,
            $this->port,
            $database,
            $this->username,
            $this->password,
            $this->sslmode,
            $this->searchPath,
        );
    }

    public function describe(): string
    {
        return "{$this->host}:{$this->port}/{$this->database}";
    }

    /** Laravel connection config array, for registering a runtime connection. */
    public function toConnectionConfig(): array
    {
        return [
            'driver'      => 'pgsql',
            'host'        => $this->host,
            'port'        => $this->port,
            'database'    => $this->database,
            'username'    => $this->username,
            'password'    => $this->password,
            'charset'     => 'utf8',
            'prefix'      => '',
            'schema'      => 'public',
            'sslmode'     => $this->sslmode ?? 'prefer',
        ];
    }
}
