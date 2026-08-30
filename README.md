# Laravel PostgreSQL Tools

[![tests](https://github.com/sergeybruhin/laravel-postgres-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/sergeybruhin/laravel-postgres-tools/actions/workflows/tests.yml)
[![license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

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
pg:backups   list the dumps on disk
pg:verify    check one dump against its manifest without restoring anything
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
{--name=                : Output filename (default: <db>-<Y-m-d_His>.<ext>)}
{--format=              : custom or plain (default: config postgres-tools.format)}
{--compress=            : Compression level 0-9}
{--schema-only          : Dump structure without data}
{--data-only            : Dump data without structure}
{--exclude-table=*      : Table pattern to omit entirely, schema included (repeatable)}
{--exclude-table-data=* : Table pattern to keep but dump empty (repeatable)}
{--only-table=*         : Restrict the dump to these table patterns (repeatable)}
{--all                  : Ignore the configured exclusions and dump everything}
{--keep=                : Keep only the N newest dumps (bare --keep uses the config value)}
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

### `pg:backups`, `pg:verify`

```bash
php artisan pg:backups              # table of dumps: size, created, format, database, server
php artisan pg:backups --checksum   # also hash every file (reads each one in full)
php artisan pg:verify               # newest dump: checksum + archive readability
php artisan pg:verify some.dump
```

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
  accordingly, and add the backup directory to `.gitignore`.

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

```php
// app/Console/Kernel.php (Laravel 10) or routes/console.php (11+)
$schedule->command('pg:backup --force --keep=7')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
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
round-tripping, checksum detection and table-pattern matching. It touches no database and needs
no `pg_dump`, so it runs anywhere. CI exercises PHP 8.1–8.4 against Laravel 10, 11 and 12.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT — see [LICENSE](LICENSE).
