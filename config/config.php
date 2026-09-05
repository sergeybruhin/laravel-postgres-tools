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

    /*
     * Offsite copies. A dump that only exists on the machine that made it is not a backup
     * of that machine. Name any disk from config/filesystems.php — s3, sftp, ftp, or a
     * second local disk on another volume. Null keeps everything local, as before.
     */
    'disk' => $value('PG_TOOLS_DISK'),

    /* Directory within that disk. */
    'disk_path' => trim((string) $value('PG_TOOLS_DISK_PATH', 'backups'), '/'),

    /* Upload every dump as soon as it is written, without needing --upload. */
    'upload_after_backup' => filter_var(
        $value('PG_TOOLS_UPLOAD_AFTER_BACKUP', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
     * Dumps to retain on the disk. Kept separate from `keep` because the two answer
     * different questions: local retention is bounded by the volume, offsite retention by
     * how far back you want to be able to go.
     */
    'keep_remote' => (int) $value('PG_TOOLS_KEEP_REMOTE', 30),

    /*
     * Hours after which the newest verified backup is considered stale. pg:check fails
     * past this, so it is both a scheduler canary and a container healthcheck.
     */
    'max_age_hours' => (int) $value('PG_TOOLS_MAX_AGE_HOURS', 26),

    /*
     * Built-in schedule. Off by default: a package that silently starts dumping your
     * database on a timer is a surprise, not a feature. Turn it on and the provider
     * registers the entries below with the application's scheduler, withoutOverlapping
     * and onOneServer, so a slow dump can never stack up behind itself.
     *
     * The scheduler must run somewhere the client binaries are installed. If artisan runs
     * in one container and the scheduler in another, both need them.
     */
    'schedule' => [
        'enabled' => filter_var($value('PG_TOOLS_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),

        /* Standard cron expression. Default: 03:00 daily. */
        'cron' => (string) $value('PG_TOOLS_SCHEDULE_CRON', '0 3 * * *'),

        /* Null uses the application's scheduler timezone. */
        'timezone' => $value('PG_TOOLS_SCHEDULE_TIMEZONE'),

        /* Retention applied by the scheduled run. Null falls back to `keep`. */
        'keep' => ($keep = $value('PG_TOOLS_SCHEDULE_KEEP')) === null ? null : (int) $keep,

        /* Upload each scheduled dump to the configured disk. */
        'upload' => filter_var($value('PG_TOOLS_SCHEDULE_UPLOAD', false), FILTER_VALIDATE_BOOLEAN),

        /*
         * Also schedule pg:check, an hour after the backup, so a scheduler that quietly
         * stopped producing dumps raises a BackupStale event instead of nothing at all.
         */
        'check'      => filter_var($value('PG_TOOLS_SCHEDULE_CHECK', true), FILTER_VALIDATE_BOOLEAN),
        'check_cron' => (string) $value('PG_TOOLS_SCHEDULE_CHECK_CRON', '0 4 * * *'),
    ],

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
