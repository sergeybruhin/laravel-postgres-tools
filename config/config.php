<?php

/**
 * An unset variable and one written as "KEY=" both mean "use the default", but env()
 * returns null for the first and an empty string for the second. Collapsing them here
 * keeps a stray blank line in .env from producing an empty backup path.
 *
 * The closure is invoked immediately, so the returned array stays plain scalars and
 * remains safe for `php artisan config:cache`.
 */
$value = static function (string $key, mixed $default = null): mixed {
    $read = env($key);

    return ($read === null || $read === '') ? $default : $read;
};

$list = static function (string $key, string $default) use ($value): array {
    return array_values(array_filter(array_map('trim', explode(',', (string) $value($key, $default)))));
};

return [
    /* Where dumps are written to and looked for. */
    'path' => $value('PG_TOOLS_PATH', storage_path('app/backups')),

    /* Connection used when --connection is not given. Null falls back to the app default. */
    'connection' => $value('PG_TOOLS_CONNECTION'),

    /* Archive format: custom (pg_restore, compressed, selective) or plain (raw SQL). */
    'format' => $value('PG_TOOLS_FORMAT', 'custom'),

    /* Compression level 0-9. Only meaningful for the custom format. */
    'compress' => (int) $value('PG_TOOLS_COMPRESS', 6),

    /* Comma-separated table patterns omitted from the dump entirely, schema included. */
    'exclude_tables' => $list('PG_TOOLS_EXCLUDE_TABLES', ''),

    /*
     * Comma-separated table patterns whose schema is kept but whose rows are dropped.
     * This is what you want for high-volume noise: excluding telescope_entries outright
     * restores into a database where Telescope is broken, excluding only its data does not.
     */
    'exclude_table_data' => $list(
        'PG_TOOLS_EXCLUDE_TABLE_DATA',
        'telescope_entries,telescope_entries_tags,telescope_monitoring,jobs,failed_jobs,sessions,cache,cache_locks'
    ),

    /* Dumps to retain when --keep is passed without a value. 0 keeps everything. */
    'keep' => (int) $value('PG_TOOLS_KEEP', 7),

    /* Absolute paths, or bare names to be resolved against PATH. */
    'binaries' => [
        'pg_dump'    => $value('PG_TOOLS_PG_DUMP', 'pg_dump'),
        'pg_restore' => $value('PG_TOOLS_PG_RESTORE', 'pg_restore'),
        'psql'       => $value('PG_TOOLS_PSQL', 'psql'),
    ],

    /* Seconds before a dump or restore process is killed. */
    'timeout' => (int) $value('PG_TOOLS_TIMEOUT', 3600),

    /* Must be true, together with --force, to restore while APP_ENV=production. */
    'allow_production_restore' => filter_var(
        $value('PG_TOOLS_ALLOW_PRODUCTION_RESTORE', false),
        FILTER_VALIDATE_BOOLEAN
    ),
];
