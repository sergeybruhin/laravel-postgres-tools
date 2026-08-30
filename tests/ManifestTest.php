<?php

namespace SergeyBruhin\PostgresTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Exceptions\ChecksumMismatchException;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\TablePattern;

class ManifestTest extends TestCase
{
    private function manifest(array $overrides = []): Manifest
    {
        return Manifest::fromArray(array_merge([
            'created_at'          => '2026-08-30T04:15:00+00:00',
            'database'            => 'app_db',
            'server_version'      => '16.4',
            'bytes'               => 2048,
            'sha256'              => hash('sha256', 'hello'),
            'excluded_table_data' => ['telescope_%'],
            'row_counts'          => ['users' => 10, 'orders' => 4, 'telescope_entries' => 0],
        ], $overrides));
    }

    public function test_it_round_trips_through_json(): void
    {
        $decoded = Manifest::fromJson($this->manifest()->toJson());

        $this->assertSame('app_db', $decoded->database);
        $this->assertSame('16.4', $decoded->serverVersion);
        $this->assertSame(16, $decoded->serverMajor());
        $this->assertSame(['users' => 10, 'orders' => 4, 'telescope_entries' => 0], $decoded->rowCounts);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->expectException(PostgresToolsException::class);

        Manifest::fromJson('{not json');
    }

    public function test_a_missing_field_falls_back_rather_than_fataling(): void
    {
        $manifest = Manifest::fromArray([]);

        $this->assertSame('', $manifest->database);
        $this->assertSame([], $manifest->rowCounts);
        $this->assertNull($manifest->serverMajor());
    }

    public function test_reconcile_reports_only_differences(): void
    {
        $checker = $this->app->make(ConsistencyChecker::class);

        $this->assertSame([], $checker->reconcile($this->manifest(), [
            'users' => 10, 'orders' => 4, 'telescope_entries' => 0,
        ]));
    }

    public function test_reconcile_flags_short_missing_and_extra_tables(): void
    {
        $checker = $this->app->make(ConsistencyChecker::class);

        $diff = $checker->reconcile($this->manifest(), [
            'users'  => 9,
            'extras' => 3,
        ]);

        $this->assertSame(['users', '10', '9'], $diff[0]);
        $this->assertSame(['orders', '4', 'missing'], $diff[1]);
        $this->assertSame(['telescope_entries', '0', 'missing'], $diff[2]);
        $this->assertSame(['extras', 'not in dump', '3'], $diff[3]);
    }

    public function test_checksum_mismatch_is_detected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($file, 'not hello');

        try {
            $this->expectException(ChecksumMismatchException::class);

            $this->app->make(ConsistencyChecker::class)->verifyChecksum($file, $this->manifest());
        } finally {
            @unlink($file);
        }
    }

    public function test_a_matching_checksum_passes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($file, 'hello');

        try {
            $this->app->make(ConsistencyChecker::class)->verifyChecksum($file, $this->manifest());
            $this->assertTrue(true);
        } finally {
            @unlink($file);
        }
    }

    #[DataProvider('patterns')]
    public function test_table_patterns(string $table, string $pattern, bool $expected): void
    {
        $this->assertSame($expected, TablePattern::matches($table, $pattern));
    }

    public static function patterns(): array
    {
        return [
            ['telescope_entries', 'telescope_entries', true],
            ['telescope_entries', 'telescope_*', true],
            ['telescope_entries', 'telescope_%', true],
            ['telescope_entries', 'public.telescope_%', true],
            ['users', 'telescope_%', false],
            ['users', '', false],
            ['users', ' users ', true],
        ];
    }
}
