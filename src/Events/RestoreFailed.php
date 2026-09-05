<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\Target;
use Throwable;

/**
 * The restore stopped part-way. The target database is in whatever state pg_restore left
 * it in, which after --drop means "empty or half-loaded" — worth saying out loud, because
 * this is the failure that takes an environment down rather than merely leaving it stale.
 */
final class RestoreFailed
{
    public function __construct(
        public readonly Target    $target,
        public readonly string    $file,
        public readonly Throwable $exception,
        public readonly float     $seconds,
    ) {}
}
