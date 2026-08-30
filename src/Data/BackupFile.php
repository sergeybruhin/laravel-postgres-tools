<?php

namespace SergeyBruhin\PostgresTools\Data;

/** A dump on disk, paired with its sidecar manifest when one exists. */
final class BackupFile
{
    public function __construct(
        public readonly string    $path,
        public readonly int       $bytes,
        public readonly int       $mtime,
        public readonly ?Manifest $manifest = null,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }

    public function manifestPath(): string
    {
        return self::manifestPathFor($this->path);
    }

    public static function manifestPathFor(string $dumpPath): string
    {
        return $dumpPath . '.json';
    }

    /** custom archives start with the magic string "PGDMP"; anything else we treat as plain. */
    public function format(): string
    {
        if ($this->manifest !== null) {
            return $this->manifest->format;
        }

        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            return 'unknown';
        }

        $magic = (string) fread($handle, 5);
        fclose($handle);

        return $magic === 'PGDMP' ? 'custom' : 'plain';
    }

    public function humanSize(): string
    {
        return self::formatBytes($this->bytes);
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)) . ' ' . $units[$i];
    }
}
