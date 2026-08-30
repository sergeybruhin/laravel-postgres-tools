<?php

namespace SergeyBruhin\PostgresTools\Data;

use JsonException;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;

/**
 * The sidecar <dump>.json written next to every dump. It is what makes a restore
 * verifiable: without it a dump is just bytes, with it we can check the checksum, refuse a
 * major-version downgrade, and reconcile row counts once the restore finishes.
 */
final class Manifest
{
    public function __construct(
        public readonly string $createdAt,
        public readonly string $appEnv,
        public readonly ?string $appVersion,
        public readonly string $connection,
        public readonly string $database,
        public readonly string $host,
        public readonly string $serverVersion,
        public readonly ?string $pgDumpVersion,
        public readonly string $format,
        public readonly int $compress,
        public readonly int $bytes,
        public readonly string $sha256,
        public readonly array $excludedTables,
        public readonly array $excludedTableData,
        public readonly array $onlyTables,
        public readonly bool $schemaOnly,
        public readonly bool $dataOnly,
        public readonly ?string $latestMigration,
        public readonly int $migrationCount,
        public readonly array $rowCounts,
    ) {}

    public function toArray(): array
    {
        return [
            'created_at'          => $this->createdAt,
            'app_env'             => $this->appEnv,
            'app_version'         => $this->appVersion,
            'connection'          => $this->connection,
            'database'            => $this->database,
            'host'                => $this->host,
            'server_version'      => $this->serverVersion,
            'pg_dump_version'     => $this->pgDumpVersion,
            'format'              => $this->format,
            'compress'            => $this->compress,
            'bytes'               => $this->bytes,
            'sha256'              => $this->sha256,
            'excluded_tables'     => $this->excludedTables,
            'excluded_table_data' => $this->excludedTableData,
            'only_tables'         => $this->onlyTables,
            'schema_only'         => $this->schemaOnly,
            'data_only'           => $this->dataOnly,
            'latest_migration'    => $this->latestMigration,
            'migration_count'     => $this->migrationCount,
            'row_counts'          => $this->rowCounts,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            createdAt:         (string) ($data['created_at'] ?? ''),
            appEnv:            (string) ($data['app_env'] ?? ''),
            appVersion:        $data['app_version'] ?? null,
            connection:        (string) ($data['connection'] ?? ''),
            database:          (string) ($data['database'] ?? ''),
            host:              (string) ($data['host'] ?? ''),
            serverVersion:     (string) ($data['server_version'] ?? ''),
            pgDumpVersion:     $data['pg_dump_version'] ?? null,
            format:            (string) ($data['format'] ?? 'custom'),
            compress:          (int) ($data['compress'] ?? 0),
            bytes:             (int) ($data['bytes'] ?? 0),
            sha256:            (string) ($data['sha256'] ?? ''),
            excludedTables:    (array) ($data['excluded_tables'] ?? []),
            excludedTableData: (array) ($data['excluded_table_data'] ?? []),
            onlyTables:        (array) ($data['only_tables'] ?? []),
            schemaOnly:        (bool) ($data['schema_only'] ?? false),
            dataOnly:          (bool) ($data['data_only'] ?? false),
            latestMigration:   $data['latest_migration'] ?? null,
            migrationCount:    (int) ($data['migration_count'] ?? 0),
            rowCounts:         (array) ($data['row_counts'] ?? []),
        );
    }

    /**
     * @throws PostgresToolsException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new PostgresToolsException('Manifest is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new PostgresToolsException('Manifest is not a JSON object.');
        }

        return self::fromArray($data);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** Major version of the server the dump came from, for downgrade detection. */
    public function serverMajor(): ?int
    {
        return preg_match('/^(\d+)/', $this->serverVersion, $m) ? (int) $m[1] : null;
    }
}
