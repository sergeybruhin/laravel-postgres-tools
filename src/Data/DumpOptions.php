<?php

namespace SergeyBruhin\PostgresTools\Data;

use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;

/** What to dump, and how. Resolved from CLI flags over config defaults. */
final class DumpOptions
{
    public const FORMAT_CUSTOM = 'custom';
    public const FORMAT_PLAIN  = 'plain';

    /**
     * @param  array<string>  $excludeTables      patterns dropped entirely, schema included
     * @param  array<string>  $excludeTableData   patterns whose schema is kept but rows dropped
     * @param  array<string>  $onlyTables         when non-empty, the dump is restricted to these
     *
     * @throws PostgresToolsException
     */
    public function __construct(
        public readonly string $format = self::FORMAT_CUSTOM,
        public readonly int    $compress = 6,
        public readonly bool   $schemaOnly = false,
        public readonly bool   $dataOnly = false,
        public readonly array  $excludeTables = [],
        public readonly array  $excludeTableData = [],
        public readonly array  $onlyTables = [],
    ) {
        if (!in_array($this->format, [self::FORMAT_CUSTOM, self::FORMAT_PLAIN], true)) {
            throw new PostgresToolsException(
                "Unknown format [{$this->format}]; expected custom or plain."
            );
        }

        if ($this->compress < 0 || $this->compress > 9) {
            throw new PostgresToolsException('Compression level must be between 0 and 9.');
        }

        if ($this->schemaOnly && $this->dataOnly) {
            throw new PostgresToolsException('--schema-only and --data-only are mutually exclusive.');
        }

        if ($this->onlyTables !== [] && ($this->excludeTables !== [] || $this->excludeTableData !== [])) {
            throw new PostgresToolsException(
                '--only-table cannot be combined with --exclude-table or --exclude-table-data. '
                . 'Pass --all to drop the configured exclusions.'
            );
        }
    }

    public function extension(): string
    {
        return $this->format === self::FORMAT_CUSTOM ? 'dump' : 'sql';
    }

    /** pg_dump's -F flag: c for the custom archive, p for plain SQL. */
    public function formatFlag(): string
    {
        return $this->format === self::FORMAT_CUSTOM ? 'c' : 'p';
    }
}
