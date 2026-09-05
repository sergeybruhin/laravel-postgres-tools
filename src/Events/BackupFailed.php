<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\Target;
use Throwable;

/**
 * The backup did not produce a verified dump. Fired for every failure after the run began
 * — a dead pg_dump, a full disk, an unreadable archive — so a listener can treat its
 * absence after a BackupStarted as a crash rather than as silence.
 */
final class BackupFailed
{
    public function __construct(
        public readonly Target    $target,
        public readonly ?string   $path,
        public readonly Throwable $exception,
        public readonly float     $seconds,
    ) {}
}
