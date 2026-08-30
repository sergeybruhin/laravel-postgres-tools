<?php

namespace SergeyBruhin\PostgresTools\Services;

use SergeyBruhin\PostgresTools\Data\Target;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;

/**
 * Supplies credentials to pg_dump / pg_restore through a 0600 PGPASSFILE.
 *
 * PGPASSWORD on the command line would be readable by any process that can run `ps`, and
 * putting it in the process environment leaves it in /proc/<pid>/environ. A short-lived
 * file with mode 0600 is the option Postgres itself documents for this.
 */
final class PgEnvironment
{
    /**
     * Run $callback with the environment pg_* tools need, then remove the password file
     * whether or not the callback threw.
     *
     * @template T
     * @param  callable(array<string, string>): T  $callback
     * @return T
     *
     * @throws PostgresToolsException
     */
    public function run(Target $target, callable $callback)
    {
        $file = $this->write($target);

        try {
            $env = [
                'PGPASSFILE' => $file,
                'PGCLIENTENCODING' => 'UTF8',
            ];

            if ($target->sslmode !== null && $target->sslmode !== '') {
                $env['PGSSLMODE'] = $target->sslmode;
            }

            return $callback($env);
        } finally {
            @unlink($file);
        }
    }

    /**
     * @throws PostgresToolsException
     */
    private function write(Target $target): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pgpass');

        if ($file === false) {
            throw new PostgresToolsException('Unable to create a temporary password file.');
        }

        // Create it empty at 0600 before any secret goes in, so it is never briefly
        // world-readable between write and chmod.
        if (!chmod($file, 0600)) {
            @unlink($file);

            throw new PostgresToolsException("Unable to restrict permissions on {$file}.");
        }

        $line = implode(':', [
            $this->escape($target->host),
            (string) $target->port,
            '*',
            $this->escape($target->username),
            $this->escape($target->password),
        ]);

        if (file_put_contents($file, $line . "\n") === false) {
            @unlink($file);

            throw new PostgresToolsException("Unable to write the temporary password file {$file}.");
        }

        return $file;
    }

    /** Colons and backslashes are field separators in .pgpass and must be escaped. */
    private function escape(string $value): string
    {
        return str_replace(['\\', ':'], ['\\\\', '\\:'], $value);
    }
}
