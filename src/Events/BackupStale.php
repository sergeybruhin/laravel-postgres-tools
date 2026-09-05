<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\BackupFile;

/**
 * pg:check found no backup recent enough to be worth having. This is the one that catches
 * the quiet failure: a scheduler that stopped running produces no BackupFailed at all,
 * because nothing ever started.
 */
final class BackupStale
{
    public function __construct(
        public readonly string      $reason,
        public readonly int         $maxAgeHours,
        public readonly ?BackupFile $newest = null,
        public readonly ?float      $ageHours = null,
    ) {}
}
