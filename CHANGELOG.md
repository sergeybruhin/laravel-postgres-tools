# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the version is below 1.0.0, command signatures, config keys and the manifest schema may
change in a minor release.

## [Unreleased]

- Packagist submission, after which version and download badges are added to the README.

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

[Unreleased]: https://github.com/sergeybruhin/laravel-postgres-tools/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/sergeybruhin/laravel-postgres-tools/releases/tag/v0.1.0
