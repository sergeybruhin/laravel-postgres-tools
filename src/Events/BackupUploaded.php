<?php

namespace SergeyBruhin\PostgresTools\Events;

/** A dump and its manifest reached the offsite disk intact. */
final class BackupUploaded
{
    public function __construct(
        public readonly string  $disk,
        public readonly string  $key,
        public readonly int     $bytes,
        public readonly string  $localPath,
        public readonly ?string $manifestKey = null,
    ) {}
}
