<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;

/** The dump directory: where files land, how they are found, and what gets rotated out. */
final class BackupRepository
{
    public function __construct(
        private readonly Config         $config,
        private readonly ManifestWriter $manifests,
    ) {}

    public function path(?string $override = null): string
    {
        return rtrim($override ?: (string) $this->config->get('postgres-tools.path'), '/');
    }

    /**
     * @throws PostgresToolsException
     */
    public function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0750, true) && !is_dir($path)) {
            throw new PostgresToolsException("Unable to create the backup directory {$path}.");
        }
    }

    /**
     * Dumps in the directory, newest first. Sidecar .json files and half-written .part
     * files are never listed as backups in their own right.
     *
     * @return array<BackupFile>
     */
    public function all(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (glob($path . '/*') ?: [] as $file) {
            if (!is_file($file) || preg_match('/\.(json|part|sha256)$/', $file)) {
                continue;
            }

            $files[] = new BackupFile(
                path:     $file,
                bytes:    (int) filesize($file),
                mtime:    (int) filemtime($file),
                manifest: $this->manifests->read($file),
            );
        }

        usort($files, static fn (BackupFile $a, BackupFile $b) => $b->mtime <=> $a->mtime);

        return $files;
    }

    public function latest(string $path): ?BackupFile
    {
        return $this->all($path)[0] ?? null;
    }

    /**
     * Resolve a user-supplied path, which may be absolute, relative to the working
     * directory, or a bare filename inside the backup directory.
     *
     * @throws PostgresToolsException
     */
    public function resolveFile(string $file, string $path): string
    {
        foreach ([$file, $path . '/' . ltrim($file, '/')] as $candidate) {
            if (is_file($candidate)) {
                return (string) (realpath($candidate) ?: $candidate);
            }
        }

        throw new PostgresToolsException(
            "Dump not found: {$file}" . PHP_EOL . "Looked in the working directory and in {$path}."
        );
    }

    /**
     * Keep the N newest dumps, deleting older ones together with their manifests.
     *
     * @return array<string> names of the deleted dumps
     */
    public function prune(string $path, int $keep): array
    {
        if ($keep < 1) {
            return [];
        }

        $deleted = [];

        foreach (array_slice($this->all($path), $keep) as $file) {
            @unlink($file->manifestPath());

            if (@unlink($file->path)) {
                $deleted[] = $file->name();
            }
        }

        return $deleted;
    }
}
