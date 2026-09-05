<?php

namespace SergeyBruhin\PostgresTools\Events;

use Throwable;

/**
 * The dump is on local disk and verified, but the offsite copy did not happen. Separate
 * from BackupFailed on purpose: the backup exists, its redundancy does not, and those two
 * usually want different responses.
 */
final class BackupUploadFailed
{
    public function __construct(
        public readonly ?string   $disk,
        public readonly string    $localPath,
        public readonly Throwable $exception,
    ) {}
}
