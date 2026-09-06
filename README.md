# Laravel PostgreSQL Tools

[![Latest version on Packagist](https://img.shields.io/packagist/v/sergeybruhin/laravel-postgres-tools.svg)](https://packagist.org/packages/sergeybruhin/laravel-postgres-tools)
[![Tests](https://github.com/sergeybruhin/laravel-postgres-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/sergeybruhin/laravel-postgres-tools/actions/workflows/tests.yml)
[![PHP version](https://img.shields.io/packagist/dependency-v/sergeybruhin/laravel-postgres-tools/php.svg)](https://packagist.org/packages/sergeybruhin/laravel-postgres-tools)
[![Total downloads](https://img.shields.io/packagist/dt/sergeybruhin/laravel-postgres-tools.svg)](https://packagist.org/packages/sergeybruhin/laravel-postgres-tools)
[![License](https://img.shields.io/packagist/l/sergeybruhin/laravel-postgres-tools.svg)](LICENSE.md)

PostgreSQL backup and restore as Artisan commands, with the checks that turn *"the command
exited 0"* into *"this dump is actually restorable"*.

Most backup scripts stop at `pg_dump; echo done`. That leaves the failures that actually hurt
undetected until the worst possible moment: a truncated file that still has the right first
bytes, a dump taken from a newer server than the one you are restoring into, a restore that
silently lands half the rows, a production snapshot that turns out to predate three migrations.
This package checks for each of those, before and after the operation.

```
pg:info      report server version, client binaries, their compatibility, and disk headroom
pg:backup    dump a database, verify the archive, write a manifest beside it
pg:restore   verify a dump, recreate the database, load it, reconcile row counts
pg:backups   list the dumps on disk, or the ones held offsite
pg:verify    check one dump against its manifest without restoring anything
pg:check     fail when there is no recent, verifiable backup
```

---

## Requirements

| | |
|---|---|
| PHP | 8.1+ |
| Laravel | 10, 11 or 12 |
| PostgreSQL | **14 or newer** — older servers are refused rather than quietly attempted |
| Binaries | `pg_dump` and `pg_restore` whose **major version matches the server**; `psql` only for plain-format restores |

The binaries are the part people get wrong, so `pg:info` exists to tell you exactly what to
install. See [Installing the client binaries](#installing-the-client-binaries).

## Installation

```bash
composer require sergeybruhin/laravel-postgres-tools
```

The service provider is auto-discovered. To customise defaults, publish the config:

```bash
php artisan vendor:publish --provider="SergeyBruhin\PostgresTools\Providers\PostgresToolsServiceProvider" --tag=config
```

## Start with `pg:info`

`pg_dump` refuses to read a server newer than itself, and an archive written by a newer
`pg_dump` cannot be loaded into an older server. Both failures are quiet until they are
expensive. `pg:info` reads the server version over PDO — it needs no client binaries itself, so
it works on a machine that is not set up yet — and names the package to install:

```
$ php artisan pg:info

+------------+---------------------------+---------+-------+---------+-----------+
| Connection | Endpoint                  | Version | Major | Size    | Reachable |
+------------+---------------------------+---------+-------+---------+-----------+
| pgsql      | db.internal:5432/app_prod | 16.4    | 16    | 4.1 GB  | yes       |
+------------+---------------------------+---------+-------+---------+-----------+
+------------+---------+---------------------------------------------+
| Binary     | Version | Path                                        |
+------------+---------+---------------------------------------------+
| pg_dump    | —       | install postgresql-client-16                |
| pg_restore | —       | install postgresql-client-16                |
| psql       | —       | optional — only for --format=plain restores |
+------------+---------+---------------------------------------------+
+------------+---------+-------------------------------------------+
| Connection | Verdict | Detail                                    |
+------------+---------+-------------------------------------------+
| pgsql      | FAIL    | pg_dump is not installed.                 |
| —          | CLIENT  | Recommended package: postgresql-client-16 |
+------------+---------+-------------------------------------------+

Findings
  [fail] pg_dump is not installed, so nothing can be dumped or restored.
         Install postgresql-client-16 on the host or image that runs artisan.
```

Once the client is installed the verdict becomes `ok (16 vs 16)`, or warns when the client is
newer than the server (`dumps taken here cannot be restored into a 16 server`), or fails when
it is older (`pg_dump will refuse to run`).

`--strict` also exits non-zero on warnings, which makes it usable as a deploy gate:

```bash
php artisan pg:info --strict || exit 1
```

## Commands

### `pg:backup`

```
{--connection=          : Laravel connection to read from (default: config default)}
{--database=            : Override the database name within that connection}
{--path=                : Output directory (default: config postgres-tools.path)}
{--name=                : Output filename (default: <site>-<db>-<Y-m-d_His>.<ext>)}
{--format=              : custom or plain (default: config postgres-tools.format)}
{--compress=            : Compression level 0-9}
{--schema-only          : Dump structure without data}
{--data-only            : Dump data without structure}
{--exclude-table=*      : Table pattern to omit entirely, schema included (repeatable)}
{--exclude-table-data=* : Table pattern to keep but dump empty (repeatable)}
{--only-table=*         : Restrict the dump to these table patterns (repeatable)}
{--all                  : Ignore the configured exclusions and dump everything}
{--keep=                : Keep only the N newest dumps (bare --keep uses the config value)}
{--upload               : Copy the finished dump and its manifest to the configured disk}
{--disk=                : Disk to upload to (default: config postgres-tools.disk)}
{--disk-path=           : Directory within that disk}
{--keep-remote=         : Keep only the N newest dumps on the disk}
{--no-manifest          : Skip the sidecar .json manifest}
{--dry-run              : Print the resolved pg_dump invocation and exit}
{--force                : Skip interactive confirmation}
```

```bash
php artisan pg:backup --force --keep=7
php artisan pg:backup --dry-run          # shows the exact argv, no credentials in it
```

### `pg:restore`

```
{file?         : Dump to restore (omit to use the newest in the backup directory)}
{--connection= : Laravel connection to restore into}
{--database=   : Override the database name within that connection}
{--path=       : Backup directory to look in}
{--from-disk   : Fetch the dump from the offsite disk first}
{--disk=       : Disk to fetch from (implies --from-disk)}
{--disk-path=  : Directory within that disk}
{--keep-download : Keep the fetched copy in the backup directory afterwards}
{--drop        : DROP and CREATE the target database before restoring}
{--clean       : pg_restore --clean --if-exists instead of dropping the database}
{--jobs=1      : Parallel restore workers (custom format only)}
{--skip-verify : Skip the checksum and archive checks}
{--skip-checks : Skip post-restore row-count and migration reconciliation}
{--dry-run     : Show what would happen and exit}
{--force       : Skip interactive confirmation}
```

```bash
php artisan pg:restore --database=app_scratch --drop --force
```

### `pg:backups`, `pg:verify`, `pg:check`

```bash
php artisan pg:backups              # table of dumps: size, created, format, database, server
php artisan pg:backups --checksum   # also hash every file (reads each one in full)
php artisan pg:backups --remote     # the same table, for the offsite disk
php artisan pg:verify               # newest dump: checksum + archive readability
php artisan pg:verify some.dump
php artisan pg:verify --remote      # checksum the offsite copy without downloading it
php artisan pg:check                # exit non-zero unless a recent, verifiable dump exists
```

`pg:check` is the one to wire into monitoring. `pg:backup` reports its own failures, but the
failure that actually loses data is quieter than that — a scheduler that stopped running, an
image rebuilt without the client binaries, a disk whose credentials expired. None of those
produce a failed backup; they produce **no** backup, and nothing to notice. `pg:check` asks the
only question that matters, of the files rather than the process:

```bash
php artisan pg:check                        # newest dump present, has a manifest, within max_age_hours
php artisan pg:check --checksum             # also verify it byte for byte
php artisan pg:check --remote               # ask the same of the offsite copy
php artisan pg:check --quiet-ok             # silent on success, for cron and container healthchecks
php artisan pg:check --max-age=6
```

It touches no database, which is what lets it answer when the database is the thing that is
broken. On failure it prints the reason, exits non-zero, and fires `BackupStale`.

## Pointing at another database

`--connection` selects the block in `config/database.php`. `--database` overrides **only** the
database name inside it, leaving host and credentials alone. That is how a dump from the live
database gets restored into a scratch one without editing any config:

```bash
php artisan pg:backup  --database=app_production
php artisan pg:restore --database=app_scratch --drop
```

Both flags work on every command. `--connection` is also how you reach a second server that has
its own entry in `config/database.php`.

## Exclusions

**Noise tables should be emptied, not removed.** `--exclude-table` drops the table *and its
schema*, so a dump that excludes `telescope_entries` restores into an application where
Telescope is broken. `--exclude-table-data` keeps the schema and drops only the rows. The
shipped default uses the latter:

```
telescope_entries, telescope_entries_tags, telescope_monitoring,
jobs, failed_jobs, sessions, cache, cache_locks
```

Patterns accept `*` and `%` as wildcards, and may be schema-qualified (`public.telescope_%`).

```bash
php artisan pg:backup --exclude-table-data='audit_%'   # per run
php artisan pg:backup --only-table=users --only-table=orders
php artisan pg:backup --all                            # ignore the configured exclusions
```

Set them per environment with `PG_TOOLS_EXCLUDE_TABLE_DATA` / `PG_TOOLS_EXCLUDE_TABLES`.
`--only-table` cannot be combined with either exclusion flag; the command tells you so rather
than silently picking one.

## Formats

**`custom`** (default) is a compressed `pg_dump` archive. It has a table of contents, so it can
be verified without restoring, loaded selectively, and restored in parallel with `--jobs`.

**`plain`** is raw SQL — readable and greppable, but there is no index to check, so the manifest
checksum is its only integrity signal, and restoring needs `psql` rather than `pg_restore`.

`pg:restore` picks the right path automatically, from the manifest when there is one and from
the archive's magic bytes when there is not.

## Offsite copies

A dump that only exists on the machine that produced it is not a backup of that machine. Name
any disk from `config/filesystems.php` and dumps go there too — s3, sftp, ftp, or simply a
second local disk on a different volume. Nothing about the package is tied to a provider.

```bash
PG_TOOLS_DISK=s3
PG_TOOLS_DISK_PATH=backups
PG_TOOLS_KEEP_REMOTE=30
```

```bash
php artisan pg:backup --force --keep=7 --upload --keep-remote
php artisan pg:backups --remote
php artisan pg:verify  --remote
php artisan pg:restore --from-disk --database=app_scratch --drop
```

Set `PG_TOOLS_UPLOAD_AFTER_BACKUP=true` to make it standing policy instead of a flag per run.

Everything **streams**. A dump is routinely larger than `memory_limit`, so nothing is ever read
into a string — uploads and downloads move through `writeStream`/`readStream`, and
`pg:verify --remote` hashes the object where it sits rather than pulling it down.

Local and offsite retention are separate settings (`keep` and `keep_remote`) because they answer
different questions: how much the local volume can hold, versus how far back you want to be able
to go.

**How a complete upload is recognised.** Locally, a dump is written to `<name>.part` and renamed
only on success, so a half-written file never wears a finished name. Object stores have no cheap
atomic rename to mirror that, so the manifest does the job instead: the dump is uploaded first
and the manifest second. The manifest is the only thing that can verify a dump, so a listing
that finds a dump without one already reports it as unverifiable rather than as good. Uploads
are also size-checked against the source, and a short one is deleted rather than left to look
like a backup.

**Restoring from a disk** downloads into a dotted staging directory inside the backup directory,
never on top of the backup directory itself — a dump fetched from the disk usually has the same
filename as the local copy it was made from. From there it takes the ordinary path: checksum,
archive parse, restore, reconcile. The staged copy is removed afterwards unless you pass
`--keep-download`; a *failed* restore leaves it in place on purpose, so a retry does not pay to
pull a multi-gigabyte dump down twice, and the next fetch clears the staging area.

If the disk is unreachable the upload fails but the backup does not: the local dump is real and
verified, and throwing it away because a bucket was down would destroy the thing that just
succeeded. You get a `BackupUploadFailed` event and a non-fatal error on stderr.

## Events

The package emits events and takes no view on what should happen next. Route them to Slack,
Telegram, email, PagerDuty, a database row — whatever you already use — from a listener in your
own application:

| Event | Meaning |
|---|---|
| `BackupStarted` | a dump is about to be written |
| `BackupCompleted` | dump written, read back by `pg_restore`, manifest recorded |
| `BackupFailed` | the run produced no verified dump |
| `BackupUploaded` | dump and manifest reached the disk intact |
| `BackupUploadFailed` | the local dump is fine, its offsite copy is not |
| `BackupPruned` | retention removed older dumps |
| `BackupStale` | `pg:check` found nothing recent enough to be worth having |
| `RestoreStarted` / `RestoreCompleted` / `RestoreFailed` | the same three for a restore |

```php
// app/Providers/AppServiceProvider.php
Event::listen(BackupFailed::class, static function (BackupFailed $event): void {
    Log::critical('Backup failed', [
        'database' => $event->target->database,
        'error'    => $event->exception->getMessage(),
    ]);
});
```

`BackupCompleted` carries the `Manifest`, so a listener can report the size, the table count and
the app version that produced the dump. `RestoreCompleted` carries the reconciliation result and
has an `isClean()` helper — a restore that lands with rows missing or the schema behind the code
still exits zero, and that is exactly the case worth alerting on.

Two details worth knowing:

- **`BackupFailed` fires even when the run is refused before it starts.** A scheduled backup
  whose `pg_dump` vanished emits nothing else, and silence is indistinguishable from a scheduler
  that stopped running. So a `BackupFailed` may arrive without a preceding `BackupStarted`; a
  `BackupStarted` with no terminal event means the process was killed outright.
- **Queue your listeners.** They run inline, inside the backup, so a slow webhook stalls the dump
  and a throwing one fails it. `ShouldQueue` keeps a notification channel from becoming a
  dependency of your backups.

## The manifest

Every dump gets a sidecar `<dump>.json`. **Copy it alongside the dump** — without it the dump
cannot be verified.

```json
{
    "created_at": "2026-08-30T04:15:00+00:00",
    "app_env": "production",
    "app_version": "25b97f8bccbb",
    "connection": "pgsql",
    "database": "app_prod",
    "host": "db.internal",
    "server_version": "16.4",
    "pg_dump_version": "16.4",
    "format": "custom",
    "compress": 6,
    "bytes": 1288490188,
    "sha256": "22802455b757c403bdd0b2d08ab47fe07e587610ec4b394fb41f448900771d43",
    "excluded_tables": [],
    "excluded_table_data": ["telescope_entries", "jobs", "sessions"],
    "only_tables": [],
    "schema_only": false,
    "data_only": false,
    "latest_migration": "2026_07_14_120000_add_x_to_y",
    "migration_count": 214,
    "row_counts": { "users": 18422, "orders": 9310, "telescope_entries": 0 }
}
```

`row_counts` are exact `count(*)` values captured *before* the dump starts, so they describe the
same snapshot `pg_dump` takes. Tables whose data is excluded are recorded as `0` and never
counted — counting `telescope_entries` can take longer than the dump itself.

## What gets checked

**Before a dump** — server reachable; the database exists; the server is 14 or newer;
client/server majors compatible; destination writable; free space measured against
`pg_database_size()` (warns below 2×).

**After a dump** — the file is non-empty, and `pg_restore --list` can read it. That last check
matters: it proves a *complete* archive, not a truncated file that happens to start correctly.

**Before a restore** — the checksum matches the manifest; the archive parses; the target is not
an older major than the dump came from; and you are warned if the target already holds tables
and neither `--drop` nor `--clean` was passed.

**After a restore** — every table's row count is compared against the manifest and differences
are tabulated; then migrations present in `database/migrations` but missing from the restored
database are listed. That last one catches the classic production-to-local surprise, where the
dump predates local work and nothing tells you until something breaks at runtime.

```
Reconciling row counts…
All 47 tables match the manifest.
12 pending migration(s) — run `php artisan migrate`:
  2026_08_01_090000_add_status_to_orders
  …
```

## Safety

- **Production is guarded.** `pg:restore` refuses to run while `APP_ENV=production` unless
  **both** `--force` and `postgres-tools.allow_production_restore` are set. `--force` alone is
  not enough.
- **Destructive restores are typed out.** When the target is the database the application is
  actually configured to use, the command asks for the database name to be typed back rather
  than accepting a yes/no anyone can click through.
- **Credentials never hit the command line.** They reach `pg_dump` through a `0600` `PGPASSFILE`
  that is unlinked afterwards — never `PGPASSWORD`, never argv, so they never appear in `ps`.
- **Partial dumps never masquerade as backups.** Output is written to `<name>.part` and renamed
  only on success.
- **Dumps contain everything.** Customer data, password hashes, API tokens. Store them
  accordingly, and add the backup directory to `.gitignore`. That applies doubly to an offsite
  disk: the bucket needs to be private, and the dumps are not encrypted by this package — if
  they leave your infrastructure, encrypt them or use a disk that does it for you.

## Configuration

| Config key | Env var | Default |
|---|---|---|
| `path` | `PG_TOOLS_PATH` | `storage_path('app/backups')` |
| `connection` | `PG_TOOLS_CONNECTION` | app default |
| `format` | `PG_TOOLS_FORMAT` | `custom` |
| `compress` | `PG_TOOLS_COMPRESS` | `6` |
| `exclude_tables` | `PG_TOOLS_EXCLUDE_TABLES` | *(empty)* |
| `exclude_table_data` | `PG_TOOLS_EXCLUDE_TABLE_DATA` | telescope, jobs, sessions, cache tables |
| `keep` | `PG_TOOLS_KEEP` | `7` |
| `disk` | `PG_TOOLS_DISK` | *(none — local only)* |
| `disk_path` | `PG_TOOLS_DISK_PATH` | `backups` |
| `upload_after_backup` | `PG_TOOLS_UPLOAD_AFTER_BACKUP` | `false` |
| `keep_remote` | `PG_TOOLS_KEEP_REMOTE` | `30` |
| `max_age_hours` | `PG_TOOLS_MAX_AGE_HOURS` | `26` |
| `schedule.enabled` | `PG_TOOLS_SCHEDULE` | `false` |
| `schedule.cron` | `PG_TOOLS_SCHEDULE_CRON` | `0 3 * * *` |
| `schedule.timezone` | `PG_TOOLS_SCHEDULE_TIMEZONE` | *(app scheduler timezone)* |
| `schedule.keep` | `PG_TOOLS_SCHEDULE_KEEP` | *(falls back to `keep`)* |
| `schedule.upload` | `PG_TOOLS_SCHEDULE_UPLOAD` | `false` |
| `schedule.check` | `PG_TOOLS_SCHEDULE_CHECK` | `true` |
| `schedule.check_cron` | `PG_TOOLS_SCHEDULE_CHECK_CRON` | `0 4 * * *` |
| `binaries.pg_dump` | `PG_TOOLS_PG_DUMP` | `pg_dump` |
| `binaries.pg_restore` | `PG_TOOLS_PG_RESTORE` | `pg_restore` |
| `binaries.psql` | `PG_TOOLS_PSQL` | `psql` |
| `timeout` | `PG_TOOLS_TIMEOUT` | `3600` |
| `allow_production_restore` | `PG_TOOLS_ALLOW_PRODUCTION_RESTORE` | `false` |

List variables are comma-separated. An unset variable and one written as `KEY=` both fall back
to the default. Binary paths may be bare names resolved against `PATH`, or absolute paths.

## Installing the client binaries

The client's **major version must match the server**. Ask `pg:info` which one you need, then
install it wherever artisan runs — and, in Docker, in every image that runs artisan (the CLI
container *and* whatever runs the scheduler, if you schedule backups).

Debian and Ubuntu ship only one or two majors natively, so pin the one you need through PGDG:

```dockerfile
ARG POSTGRES_CLIENT_VERSION=16

RUN install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] \
        http://apt.postgresql.org/pub/repos/apt $(. /etc/os-release && echo $VERSION_CODENAME)-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-${POSTGRES_CLIENT_VERSION} \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
```

Alpine: `apk add postgresql16-client`. macOS: `brew install libpq` (then either link it or point
`PG_TOOLS_PG_DUMP` at `/opt/homebrew/opt/libpq/bin/pg_dump`).

Keep the major in step across every environment. If your local server is 14 and production is
16, dumps taken in production cannot be restored locally — `pg:info --strict` fails the moment
they drift.

## Scheduling

The package can register its own schedule. It is off by default — a package that starts dumping
your database on a timer the moment it is installed is a surprise, not a convenience:

```bash
PG_TOOLS_SCHEDULE=true
PG_TOOLS_SCHEDULE_CRON="0 3 * * *"
PG_TOOLS_SCHEDULE_UPLOAD=true
```

That registers `pg:backup --force --keep=<keep>` at the given cron, `withoutOverlapping(60)` and
`onOneServer` — a dump slower than its interval must not start a second one on top of the first,
because two `pg_dump`s against one server is how a backup window becomes an outage. It also
schedules `pg:check --quiet-ok` an hour later, so a scheduler that quietly stopped producing
dumps raises `BackupStale` rather than nothing at all. Set `PG_TOOLS_SCHEDULE_CHECK=false` to
skip that half.

Prefer to wire it yourself:

```php
// app/Console/Kernel.php (Laravel 10) or routes/console.php (11+)
$schedule->command('pg:backup --force --keep=7 --upload --keep-remote')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);

$schedule->command('pg:check --quiet-ok')->dailyAt('04:00');
```

The scheduler must run on a host or image that has the client binaries. If your scheduler runs
in a different container from your CLI, install them in both.

## Troubleshooting

**`pg_dump not found`** — run `php artisan pg:info`; it names the exact package. If the binary
is installed somewhere unusual, set `PG_TOOLS_PG_DUMP` to its absolute path.

**`pg_dump N is older than the server`** — `pg_dump` refuses outright. Install a client matching
the server's major.

**`pg_dump N is newer than the server`** — allowed, but the resulting archive cannot be loaded
into the older server. Align the majors.

**`Checksum mismatch`** — the dump is corrupt or truncated, usually from an interrupted copy.
Re-copy it. `--skip-verify` overrides, but you are restoring an archive known to be damaged.

**`Database [x] does not exist`** — fine for `pg:restore`, which creates it; fatal for
`pg:backup`, which has nothing to read.

**`restoring into an older major version is not supported`** — the dump came from a newer
server than the target. Restore into a matching server, or take the dump from an older one.

## Testing

```bash
composer install
composer test
```

The suite covers connection-override precedence, argv construction, version verdicts, manifest
round-tripping, checksum detection, table-pattern matching, the offsite disk (against
`Storage::fake`), staleness detection and schedule registration. It touches no database, needs
no `pg_dump` and no bucket, so it runs anywhere. CI exercises PHP 8.1–8.4 against Laravel 10, 11
and 12.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release. This package follows
[semantic versioning](https://semver.org); while the version is below 1.0.0, config keys,
event payloads and command options may change in a minor release.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

This package's whole subject matter is files that contain an entire database. If you find a
vulnerability, please report it as described in [SECURITY.md](SECURITY.md) rather than opening
a public issue.

## Credits

- [Sergey Bruhin](https://github.com/sergeybruhin)
- [All contributors](https://github.com/sergeybruhin/laravel-postgres-tools/contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
