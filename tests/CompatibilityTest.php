<?php

namespace SergeyBruhin\PostgresTools\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SergeyBruhin\PostgresTools\Data\Finding;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Services\BinaryLocator;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\EnvironmentReport;

class CompatibilityTest extends TestCase
{
    private function report(): EnvironmentReport
    {
        return $this->app->make(EnvironmentReport::class);
    }

    public function test_a_matching_client_is_ok(): void
    {
        $this->assertSame(Finding::OK, $this->report()->compatibility(16, 16)->level);
    }

    public function test_an_older_client_fails_because_pg_dump_refuses(): void
    {
        $finding = $this->report()->compatibility(14, 16);

        $this->assertSame(Finding::FAIL, $finding->level);
        $this->assertStringContainsString('older than the server', $finding->message);
    }

    public function test_a_newer_client_warns_about_unrestorable_archives(): void
    {
        $finding = $this->report()->compatibility(17, 16);

        $this->assertSame(Finding::WARN, $finding->level);
        $this->assertStringContainsString('cannot be restored', $finding->message);
    }

    public function test_a_missing_client_names_the_package_to_install(): void
    {
        $finding = $this->report()->compatibility(null, 16);

        $this->assertSame(Finding::FAIL, $finding->level);
        $this->assertStringContainsString('postgresql-client-16', (string) $finding->hint);
    }

    public function test_an_unreachable_server_cannot_be_judged(): void
    {
        $this->assertSame(Finding::FAIL, $this->report()->compatibility(16, null)->level);
    }

    #[DataProvider('serverMajors')]
    public function test_servers_below_the_supported_floor_are_refused(int $major, bool $supported): void
    {
        $finding = $this->report()->supportFinding($major, $major . '.1');

        if ($supported) {
            $this->assertNull($finding);

            return;
        }

        $this->assertNotNull($finding);
        $this->assertSame(Finding::FAIL, $finding->level);
        $this->assertStringContainsString('below the minimum', $finding->message);
    }

    public static function serverMajors(): array
    {
        return [
            'pg 12 refused'  => [12, false],
            'pg 13 refused'  => [13, false],
            'pg 14 accepted' => [14, true],
            'pg 15 accepted' => [15, true],
            'pg 17 accepted' => [17, true],
        ];
    }

    public function test_an_unknown_server_version_is_not_treated_as_unsupported(): void
    {
        $this->assertNull($this->report()->supportFinding(null, null));
    }

    public function test_the_floor_is_fourteen(): void
    {
        $this->assertSame(14, EnvironmentReport::MINIMUM_SERVER_MAJOR);
    }

    public function test_the_recommended_package_tracks_the_server(): void
    {
        $this->assertSame('postgresql-client-14', $this->report()->recommendedClientPackage(14));
        $this->assertSame('postgresql-client-17', $this->report()->recommendedClientPackage(17));
        $this->assertNull($this->report()->recommendedClientPackage(null));
    }

    #[DataProvider('versionStrings')]
    public function test_major_version_parsing(?string $version, ?int $expected): void
    {
        $this->assertSame($expected, BinaryLocator::major($version));
    }

    public static function versionStrings(): array
    {
        return [
            ['16.4', 16],
            ['14.24', 14],
            ['9.6.24', 9],
            ['17', 17],
            [null, null],
            ['not a version', null],
        ];
    }

    public function test_restoring_into_an_older_major_is_refused(): void
    {
        $checker = $this->app->make(ConsistencyChecker::class);
        $finding = $checker->versionFinding($this->manifestFrom('16.4'), 14);

        $this->assertSame(Finding::FAIL, $finding->level);
        $this->assertStringContainsString('not supported', $finding->message);
    }

    public function test_restoring_into_a_newer_major_only_warns(): void
    {
        $checker = $this->app->make(ConsistencyChecker::class);

        $this->assertSame(Finding::WARN, $checker->versionFinding($this->manifestFrom('14.24'), 16)->level);
        $this->assertSame(Finding::OK, $checker->versionFinding($this->manifestFrom('14.24'), 14)->level);
    }

    private function manifestFrom(string $serverVersion): Manifest
    {
        return Manifest::fromArray([
            'server_version' => $serverVersion,
            'database'       => 'app_db',
        ]);
    }
}
