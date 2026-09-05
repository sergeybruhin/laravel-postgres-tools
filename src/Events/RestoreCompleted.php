<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;

/**
 * The dump loaded. Carries the reconciliation result, so a listener can tell a clean
 * restore from one that landed with rows missing or with the schema behind the code —
 * both of which exit zero.
 */
final class RestoreCompleted
{
    /**
     * @param  array<int, array{0: string, 1: int|string, 2: int|string}>  $rowCountDifferences
     * @param  array<string>  $pendingMigrations
     * @param  array<string>  $warnings
     */
    public function __construct(
        public readonly Target    $target,
        public readonly string    $file,
        public readonly float     $seconds,
        public readonly ?Manifest $manifest = null,
        public readonly array     $warnings = [],
        public readonly array     $rowCountDifferences = [],
        public readonly array     $pendingMigrations = [],
    ) {}

    public function isClean(): bool
    {
        return $this->rowCountDifferences === [] && $this->pendingMigrations === [];
    }
}
