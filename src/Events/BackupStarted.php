<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\DumpOptions;
use SergeyBruhin\PostgresTools\Data\Target;

/** A dump is about to be written. Paired with exactly one BackupCompleted or BackupFailed. */
final class BackupStarted
{
    public function __construct(
        public readonly Target      $target,
        public readonly DumpOptions $options,
        public readonly string      $path,
    ) {}
}
