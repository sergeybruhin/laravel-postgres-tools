<?php

namespace SergeyBruhin\PostgresTools\Events;

/** Retention removed older dumps. $location is a directory, or "<disk>:<prefix>" offsite. */
final class BackupPruned
{
    /** @param array<string> $deleted */
    public function __construct(
        public readonly array  $deleted,
        public readonly int    $keep,
        public readonly string $location,
        public readonly bool   $offsite = false,
    ) {}
}
