<?php

namespace SergeyBruhin\PostgresTools\Events;

use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Data\Target;

/** A restore has passed its guards and is about to overwrite the target database. */
final class RestoreStarted
{
    public function __construct(
        public readonly Target    $target,
        public readonly string    $file,
        public readonly ?Manifest $manifest = null,
    ) {}
}
