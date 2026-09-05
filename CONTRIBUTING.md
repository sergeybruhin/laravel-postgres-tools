# Contributing

Bug reports, questions and pull requests are welcome.

## Reporting a bug

Include the output of `php artisan pg:info` — it names the server version, the client binaries
and their compatibility, which is what most reports turn out to hinge on. Redact the host and
database name if you would rather not publish them; the version rows are the part that matters.

## Pull requests

```bash
composer install
composer test
```

The suite runs on PHP 8.1–8.4 against Laravel 10, 11 and 12 in CI, so keep new code inside that
intersection: no syntax newer than 8.1, no framework API that only exists in 12.

Three rules the existing tests hold to, and new ones should too:

- **No database.** The suite must run on a machine with no PostgreSQL installed.
- **No `pg_dump`.** Argv construction is asserted; the binary is never executed.
- **No network.** Offsite behaviour is tested against `Storage::fake()`.

That is what keeps the suite runnable anywhere, and it is worth more than the coverage a live
database would buy.

Please add a line to `CHANGELOG.md` under an `Unreleased` heading, and keep the README accurate
in the same commit as the behaviour it documents.

## Adding a check

The point of this package is catching the failure *before* it costs someone a restore. A new
check should be able to answer: what silent corruption does this notice, and what does the
operator do about it? Both belong in the message the user sees, not only in the code.
