<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use Throwable;

/**
 * The offsite half of the backup directory: the same list / latest / prune vocabulary as
 * BackupRepository, against any disk from config/filesystems.php.
 *
 * A dump that only exists on the machine that produced it is not a backup of that machine.
 * Everything here streams — a dump is routinely larger than memory_limit, so nothing is
 * ever read into a string.
 */
final class RemoteRepository
{
    public function __construct(
        private readonly Config            $config,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /** Name of the configured disk, or null when no offsite copy is configured. */
    public function diskName(?string $override = null): ?string
    {
        $name = $override ?: $this->config->get('postgres-tools.disk');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function enabled(?string $override = null): bool
    {
        return $this->diskName($override) !== null;
    }

    /**
     * @throws PostgresToolsException
     */
    public function disk(?string $override = null): Filesystem
    {
        $name = $this->diskName($override);

        if ($name === null) {
            throw new PostgresToolsException(
                'No offsite disk configured. Set PG_TOOLS_DISK to a disk from config/filesystems.php, '
                . 'or pass --disk=<name>.'
            );
        }

        try {
            return $this->filesystems->disk($name);
        } catch (Throwable $e) {
            throw new PostgresToolsException(
                "Disk [{$name}] could not be resolved: " . $e->getMessage() . PHP_EOL
                . 'Check that it exists in config/filesystems.php and that its credentials are set.',
                0,
                $e
            );
        }
    }

    public function directory(?string $override = null): string
    {
        return trim($override ?? (string) $this->config->get('postgres-tools.disk_path', 'backups'), '/');
    }

    public function key(string $name, ?string $directory = null): string
    {
        $directory = $this->directory($directory);

        return ($directory === '' ? '' : $directory . '/') . basename($name);
    }

    /**
     * Copy a dump and its manifest to the disk.
     *
     * The dump goes first and the manifest second, deliberately. Object stores have no
     * cheap atomic rename to mirror the local `.part` trick, so the manifest doubles as the
     * completion marker: it is the only thing that can verify a dump, and a listing that
     * finds a dump without one already reports it as unverifiable rather than as good.
     *
     * @return array{key: string, bytes: int, manifest: ?string}
     *
     * @throws PostgresToolsException
     */
    public function upload(
        string  $localPath,
        ?string $disk = null,
        ?string $directory = null,
        ?callable $onProgress = null,
    ): array {
        if (!is_file($localPath)) {
            throw new PostgresToolsException("Nothing to upload: {$localPath} does not exist.");
        }

        $filesystem = $this->disk($disk);
        $key        = $this->key($localPath, $directory);
        $bytes      = (int) filesize($localPath);

        $onProgress && $onProgress("Uploading " . basename($localPath) . ' (' . BackupFile::formatBytes($bytes) . ')…');

        $this->putStream($filesystem, $key, $localPath);

        // Proves the whole file arrived, not just the first chunk. Cheap: a HEAD, not a GET.
        $written = (int) ($filesystem->size($key) ?: 0);

        if ($written !== $bytes) {
            $filesystem->delete($key);

            throw new PostgresToolsException(
                "Upload of {$key} is short: {$written} bytes arrived of {$bytes}. The partial copy was removed."
            );
        }

        $manifestKey  = null;
        $localManifest = BackupFile::manifestPathFor($localPath);

        if (is_file($localManifest)) {
            $manifestKey = BackupFile::manifestPathFor($key);
            $this->putStream($filesystem, $manifestKey, $localManifest);
            $onProgress && $onProgress('Manifest uploaded.');
        } else {
            $onProgress && $onProgress('No local manifest to upload — the remote copy cannot be verified.');
        }

        return ['key' => $key, 'bytes' => $bytes, 'manifest' => $manifestKey];
    }

    /**
     * Fetch a dump and its manifest to a local directory, returning the local dump path.
     *
     * @throws PostgresToolsException
     */
    public function download(
        string    $name,
        string    $destinationDirectory,
        ?string   $disk = null,
        ?string   $directory = null,
        ?callable $onProgress = null,
    ): string {
        $filesystem = $this->disk($disk);
        $key        = $this->key($name, $directory);

        if (!$filesystem->exists($key)) {
            throw new PostgresToolsException(
                "No dump named [" . basename($name) . '] on disk [' . $this->diskName($disk) . "] under " . $this->directory($directory) . '/.'
            );
        }

        $destination = rtrim($destinationDirectory, '/') . '/' . basename($name);
        $partial     = $destination . '.part';

        $onProgress && $onProgress('Downloading ' . basename($key) . '…');

        $this->getStream($filesystem, $key, $partial);

        // Same guarantee the dump path gives locally: a half-written file never wears the
        // name of a finished one.
        if (!rename($partial, $destination)) {
            @unlink($partial);

            throw new PostgresToolsException("Unable to move the downloaded dump into place at {$destination}.");
        }

        $manifestKey = BackupFile::manifestPathFor($key);

        if ($filesystem->exists($manifestKey)) {
            $this->getStream($filesystem, $manifestKey, BackupFile::manifestPathFor($destination));
        }

        return $destination;
    }

    /**
     * Dumps on the disk, newest first.
     *
     * @return array<BackupFile>
     *
     * @throws PostgresToolsException
     */
    public function all(?string $disk = null, ?string $directory = null): array
    {
        $filesystem = $this->disk($disk);
        $prefix     = $this->directory($directory);
        $files      = [];

        foreach ($filesystem->files($prefix === '' ? '/' : $prefix) as $key) {
            if (preg_match('/\.(json|part|sha256)$/', $key)) {
                continue;
            }

            $files[] = new BackupFile(
                path:        $key,
                bytes:       (int) ($filesystem->size($key) ?: 0),
                mtime:       (int) ($filesystem->lastModified($key) ?: 0),
                manifest:    $this->manifest($key, $disk),
                knownFormat: BackupFile::formatFromName($key),
            );
        }

        usort($files, static fn (BackupFile $a, BackupFile $b) => $b->mtime <=> $a->mtime);

        return $files;
    }

    /**
     * @throws PostgresToolsException
     */
    public function latest(?string $disk = null, ?string $directory = null): ?BackupFile
    {
        return $this->all($disk, $directory)[0] ?? null;
    }

    /**
     * @throws PostgresToolsException
     */
    public function manifest(string $key, ?string $disk = null): ?Manifest
    {
        $filesystem  = $this->disk($disk);
        $manifestKey = BackupFile::manifestPathFor($key);

        if (!$filesystem->exists($manifestKey)) {
            return null;
        }

        $json = $filesystem->get($manifestKey);

        if ($json === null || $json === '') {
            return null;
        }

        return Manifest::fromJson($json);
    }

    /**
     * sha256 of a remote dump, streamed rather than downloaded, so verifying a 4 GB archive
     * costs bandwidth but no disk.
     *
     * @throws PostgresToolsException
     */
    public function checksum(string $key, ?string $disk = null): string
    {
        $stream = $this->disk($disk)->readStream($key);

        if (!is_resource($stream)) {
            throw new PostgresToolsException("Unable to read {$key} from the disk to compute a checksum.");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Keep the N newest dumps on the disk, deleting older ones with their manifests.
     *
     * @return array<string> names of the deleted dumps
     *
     * @throws PostgresToolsException
     */
    public function prune(int $keep, ?string $disk = null, ?string $directory = null): array
    {
        if ($keep < 1) {
            return [];
        }

        $filesystem = $this->disk($disk);
        $deleted    = [];

        foreach (array_slice($this->all($disk, $directory), $keep) as $file) {
            $filesystem->delete(BackupFile::manifestPathFor($file->path));

            if ($filesystem->delete($file->path)) {
                $deleted[] = $file->name();
            }
        }

        return $deleted;
    }

    /**
     * @throws PostgresToolsException
     */
    private function putStream(Filesystem $filesystem, string $key, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');

        if (!is_resource($stream)) {
            throw new PostgresToolsException("Unable to open {$localPath} for upload.");
        }

        try {
            // writeStream leaves the handle open for the caller to close, hence the finally.
            if ($filesystem->writeStream($key, $stream) === false) {
                throw new PostgresToolsException("The disk rejected the upload of {$key}.");
            }
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    /**
     * @throws PostgresToolsException
     */
    private function getStream(Filesystem $filesystem, string $key, string $destination): void
    {
        $source = $filesystem->readStream($key);

        if (!is_resource($source)) {
            throw new PostgresToolsException("Unable to read {$key} from the disk.");
        }

        $handle = fopen($destination, 'wb');

        if (!is_resource($handle)) {
            fclose($source);

            throw new PostgresToolsException("Unable to write to {$destination}.");
        }

        try {
            if (stream_copy_to_stream($source, $handle) === false) {
                throw new PostgresToolsException("Download of {$key} failed part-way through.");
            }
        } finally {
            fclose($source);
            fclose($handle);
        }
    }
}
