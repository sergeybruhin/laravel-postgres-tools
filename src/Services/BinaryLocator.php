<?php

namespace SergeyBruhin\PostgresTools\Services;

use Illuminate\Contracts\Config\Repository as Config;
use SergeyBruhin\PostgresTools\Exceptions\BinaryNotFoundException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Finds pg_dump / pg_restore / psql and reports their versions.
 *
 * Two entry points on purpose: require() throws so a dump fails loudly, probe() returns
 * null so the readiness report can say "not installed" instead of dying on the spot.
 */
final class BinaryLocator
{
    /** @var array<string, string|null> */
    private array $pathCache = [];

    /** @var array<string, string|null> */
    private array $versionCache = [];

    public function __construct(
        private readonly Config $config,
    ) {}

    public function path(string $binary): ?string
    {
        if (array_key_exists($binary, $this->pathCache)) {
            return $this->pathCache[$binary];
        }

        $configured = (string) $this->config->get("postgres-tools.binaries.{$binary}", $binary);

        // An absolute path is taken at face value; a bare name goes through PATH.
        $resolved = str_contains($configured, DIRECTORY_SEPARATOR)
            ? (is_executable($configured) ? $configured : null)
            : (new ExecutableFinder())->find($configured);

        return $this->pathCache[$binary] = $resolved;
    }

    public function has(string $binary): bool
    {
        return $this->path($binary) !== null;
    }

    /**
     * @throws BinaryNotFoundException
     */
    public function require(string $binary): string
    {
        $path = $this->path($binary);

        if ($path === null) {
            throw new BinaryNotFoundException($this->missingMessage($binary));
        }

        return $path;
    }

    /** Full version string ("16.4"), or null when the binary is absent or unreadable. */
    public function probe(string $binary): ?string
    {
        if (array_key_exists($binary, $this->versionCache)) {
            return $this->versionCache[$binary];
        }

        $path = $this->path($binary);

        if ($path === null) {
            return $this->versionCache[$binary] = null;
        }

        $process = new Process([$path, '--version']);
        $process->setTimeout(15);
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->versionCache[$binary] = null;
        }

        // "pg_dump (PostgreSQL) 16.4 (Debian 16.4-1.pgdg130+1)"
        $matched = preg_match('/(\d+(?:\.\d+)*)/', $process->getOutput(), $m);

        return $this->versionCache[$binary] = $matched ? $m[1] : null;
    }

    public static function major(?string $version): ?int
    {
        if ($version === null) {
            return null;
        }

        return preg_match('/^(\d+)/', $version, $m) ? (int) $m[1] : null;
    }

    private function missingMessage(string $binary): string
    {
        return implode("\n", [
            "{$binary} not found in PATH or config('postgres-tools.binaries.{$binary}').",
            'Install the postgresql-client package whose major version matches your server',
            'on whatever host or image runs artisan. Run `php artisan pg:info` for the exact',
            'package name — it reports the version of the server it actually reaches.',
            'If the binary is installed somewhere unusual, point at it directly instead:',
            "  PG_TOOLS_" . strtoupper($binary) . "=/full/path/to/{$binary}",
        ]);
    }
}
