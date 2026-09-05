# Security policy

## Reporting a vulnerability

Please email **sundaycreative@gmail.com** rather than opening a public issue. You should get a
reply within a few days.

## What this package handles

Every dump it writes is the entire database in one file: every row, every password hash, every
API token. That shapes what counts as a vulnerability here — anything that could expose a dump,
its manifest, or the credentials used to produce it.

Two things are worth knowing when deploying it, neither of which is a bug:

- **Dumps are not encrypted.** They are written with the permissions of the process that ran the
  command. Keep the backup directory outside the web root, restrict it at the filesystem level,
  and encrypt the disk they land on — particularly when `PG_TOOLS_DISK` sends them to an object
  store, where the bucket's own policy becomes the only thing between the file and the internet.
- **The database password is never passed on the command line.** It goes to `pg_dump` and
  `pg_restore` through the environment, so it does not appear in `ps` output or in the argv that
  `--dry-run` prints. If you find a path where it does leak into a log, a message or a process
  list, that is a vulnerability — please report it.
