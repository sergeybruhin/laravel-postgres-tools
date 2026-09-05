<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;

/**
 * A dump was written, read back by pg_restore, and its manifest recorded. This is the
 * event that means "there is a restorable backup", not merely "pg_dump exited 0".
 */
final class BackupCompleted
{
    public function __construct(
        public readonly Target    $target,
        public readonly string    $path,
        public readonly int       $bytes,
        public readonly float     $seconds,
        public readonly ?Manifest $manifest = null,
    ) {}
}
