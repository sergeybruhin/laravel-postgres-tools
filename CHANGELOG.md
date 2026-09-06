# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version is below 1.0.0, command signatures, config keys and the manifest schema may
change in a minor release.

## [Unreleased]

- Packagist submission, after which version and download badges are added to the README.

## [0.4.0] - 2026-09-06

### Changed

- The default `pg:backup` filename now starts with a lowercase, Latin-only slug of
  `config('app.name')` — `sergeybruhincom-homestead-2026-09-06_014501.dump` rather than
  `homestead-2026-09-06_014501.dump`. A raw database name says nothing about which of several
  sites a dump came from once it is sitting in a shared bucket or a laptop's Downloads folder;
  the site's own name does. Built with `Str::slug()`, so an `APP_NAME` with spaces, punctuation
  or non-Latin script still produces a filename every shell and filesystem handles unquoted; a
  blank or symbols-only name falls back to `app`. Nothing parses the filename structure — the
  database name shown in Nova and `pg:backups` always comes from the manifest — so this is
  cosmetic and `--name` still overrides it outright.

## [0.3.1] - 2026-09-06

### Security

- Added `BackupRepository::resolveInDirectory()`, which resolves a filename strictly inside
  the backup directory — never the process's working directory, never an absolute path, never
  able to escape via `..` or a symlink. `resolveFile()`'s working-directory fallback is
  intentional for a trusted CLI operator typing a path they already know exists, but it is not
  safe to reach with a filename taken from an HTTP request: a bare name like `.env` resolves
  against the app root under php-fpm and hands back the application's real `.env` instead of
  "not found". `nova-postgres-tools` ^0.2.1 uses the new method for every endpoint that takes a
  filename from the request; `pg:backup`/`pg:restore`/`pg:verify` are unaffected.

## [0.3.0] - 2026-09-06

### Added

- `EnvironmentReport::findings()` takes an optional `$binaryVersions` override, so a caller that
  probed `pg_dump`/`pg_restore` somewhere other than its own process — a queue worker, checked
  from the web request that renders a status page — can report against those versions instead of
  the local (and possibly binary-less) ones. `pg:info`/`pg:backup`/`pg:restore` are unaffected;
  they still probe locally, which is always correct for a process that is about to shell out.

## [0.2.0] - 2026-09-05

Offsite copies, events, a staleness check, and a schedule.

### Added

- **Offsite storage on any Laravel disk.** `PG_TOOLS_DISK` names a disk from
  `config/filesystems.php` — s3, sftp, ftp, or a second local disk — and `pg:backup --upload`
  copies the dump and its manifest there. `pg:backups --remote` lists it, `pg:verify --remote`
  hashes an object in place without downloading it, and `pg:restore --from-disk` fetches a dump
  before restoring it — staged in a dotted directory inside the backup directory, never on top
  of it, since a dump fetched from a disk usually carries the same filename as the local copy it
  was made from. Separate `keep_remote` retention, because a local volume and an archive bucket
  are sized for different questions. Everything streams, so a dump larger than
  `memory_limit` is not a problem. Uploads are size-checked and a short one is deleted rather
  than left looking like a backup; the manifest is uploaded last and doubles as the
  completion marker that object stores cannot express with an atomic rename.
- **Events**, so the host application decides what a backup outcome means:
  `BackupStarted`, `BackupCompleted`, `BackupFailed`, `BackupUploaded`, `BackupUploadFailed`,
  `BackupPruned`, `BackupStale`, `RestoreStarted`, `RestoreCompleted`, `RestoreFailed`.
  `BackupCompleted` carries the manifest; `RestoreCompleted` carries the reconciliation result
  and an `isClean()` helper, because a restore that lands with rows missing still exits zero.
  `BackupFailed` also fires when a run is refused before it begins — a scheduled backup whose
  `pg_dump` vanished would otherwise emit nothing, and silence is indistinguishable from a
  scheduler that stopped running.
- **`pg:check`** — exits non-zero unless a recent, verifiable dump exists: present, manifested,
  within `max_age_hours`, size matching, and with `--checksum` byte-identical. Works against
  `--remote` too, touches no database, and has `--quiet-ok` for cron and container healthchecks.
  Fires `BackupStale`. This is the command that catches the failure `pg:backup` cannot report,
  because nothing ran.
- **Built-in schedule**, opt-in via `PG_TOOLS_SCHEDULE=true`: registers `pg:backup` at
  `schedule.cron` with `withoutOverlapping(60)` and `onOneServer`, plus `pg:check` an hour later.
  Off by default.

### Fixed

- An option given as `0` was read as "unset" and fell back to the configured value, so
  `--keep=0` did not disable rotation and `--max-age=0` behaved like the most lenient setting
  rather than the strictest.

### Requirements

- Adds `illuminate/filesystem` and `illuminate/contracts`, both already present in any Laravel
  application. PHP, Laravel and PostgreSQL requirements are unchanged.

## [0.1.0] - 2026-08-30

Initial release.

### Added

- `pg:info` — reports server version, client binary versions, their major-version
  compatibility, and backup-directory headroom. Reads the server over PDO, so it works before
  any client binary is installed and names the exact package to install. `--strict` exits
  non-zero on warnings for use as a deploy gate.
- `pg:backup` — dumps to the custom or plain format with `--schema-only` / `--data-only`,
  repeatable `--exclude-table`, `--exclude-table-data` and `--only-table` patterns, `--all` to
  ignore configured exclusions, `--keep` retention, `--dry-run` and `--force`.
- `pg:restore` — verifies the dump, optionally drops and recreates the target database, loads
  it, then reconciles row counts against the manifest and reports pending migrations. Supports
  `--drop`, `--clean`, `--jobs`, `--skip-verify`, `--skip-checks`, `--dry-run` and `--force`.
- `pg:backups` — lists dumps on disk with their manifest details; `--checksum` verifies each.
- `pg:verify` — checks one dump against its manifest without restoring.
- `--connection` and `--database` overrides on every command: `--connection` selects the
  `config/database.php` block, `--database` swaps only the database name within it, so a dump
  from one database can be restored into another without touching config.
- Sidecar `<dump>.json` manifest recording server version, sha256, exact per-table row counts
  captured before the dump, excluded patterns, and migration state.
- Integrity checks at four points: before a dump (reachability, version floor, client
  compatibility, free space), after a dump (`pg_restore --list` proves a complete archive),
  before a restore (checksum, archive parse, major-version downgrade refusal), and after a
  restore (row-count reconciliation, pending migrations).
- Production restore guard requiring both `--force` and `allow_production_restore`, plus typed
  database-name confirmation when the target is the application's configured database.
- Credentials passed via a `0600` `PGPASSFILE` that is removed afterwards, never via
  `PGPASSWORD` or the command line.
- Dumps written to `<name>.part` and renamed only on success.
- `DROP DATABASE` / `CREATE DATABASE` performed over PDO through the `postgres` maintenance
  database, so only `pg_dump` and `pg_restore` are required; `psql` is needed only for
  plain-format restores.

### Requirements

- PHP 8.1+, Laravel 10/11/12, PostgreSQL 14+. Servers below 14 are refused rather than
  quietly attempted.

[Unreleased]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sergeybruhin/laravel-postgres-tools/releases/tag/v0.1.0
