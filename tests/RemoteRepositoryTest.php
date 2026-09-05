<?php

namespace SergeyBruhin\PostgresTools\Tests;

use Illuminate\Support\Facades\Storage;
use SergeyBruhin\PostgresTools\Data\BackupFile;
use SergeyBruhin\PostgresTools\Data\Manifest;
use SergeyBruhin\PostgresTools\Exceptions\PostgresToolsException;
use SergeyBruhin\PostgresTools\Services\RemoteRepository;

/**
 * The offsite half of the backup directory. Nothing here needs a database or a bucket:
 * Storage::fake gives a real filesystem behind the same contract S3 implements.
 */
class RemoteRepositoryTest extends TestCase
{
    private string $local;

    protected function setUp(): void
    {
        parent::setUp();

        $this->local = sys_get_temp_dir() . '/pgtools-' . bin2hex(random_bytes(6));
        mkdir($this->local, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->local . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->local);

        parent::tearDown();
    }

    private function repository(): RemoteRepository
    {
        return $this->app->make(RemoteRepository::class);
    }

    /** Writes a dump and, unless told otherwise, the manifest that makes it verifiable. */
    private function makeDump(string $name = 'app-2026-01-01_000000.dump', bool $withManifest = true): string
    {
        $path = $this->local . '/' . $name;
        file_put_contents($path, 'PGDMP' . str_repeat('x', 200));

        if ($withManifest) {
            file_put_contents(
                BackupFile::manifestPathFor($path),
                $this->manifestFor($path)->toJson()
            );
        }

        return $path;
    }

    private function manifestFor(string $path): Manifest
    {
        return Manifest::fromArray([
            'database'       => 'app_db',
            'format'         => 'custom',
            'server_version' => '16.4',
            'bytes'          => filesize($path),
            'sha256'         => hash_file('sha256', $path),
            'row_counts'     => ['users' => 3],
        ]);
    }

    public function test_it_is_disabled_until_a_disk_is_configured(): void
    {
        $this->assertFalse($this->repository()->enabled());
        $this->assertNull($this->repository()->diskName());

        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->assertTrue($this->repository()->enabled());
        $this->assertSame('offsite', $this->repository()->diskName());
    }

    public function test_it_refuses_to_act_without_a_disk_rather_than_guessing_one(): void
    {
        $this->expectException(PostgresToolsException::class);
        $this->expectExceptionMessage('No offsite disk configured');

        $this->repository()->disk();
    }

    public function test_the_disk_can_be_overridden_per_call(): void
    {
        Storage::fake('elsewhere');

        $this->assertSame('elsewhere', $this->repository()->diskName('elsewhere'));
    }

    public function test_keys_are_prefixed_with_the_configured_directory(): void
    {
        $this->app['config']->set('postgres-tools.disk_path', 'db/dumps');

        $this->assertSame('db/dumps/app.dump', $this->repository()->key('/tmp/nested/app.dump'));
        $this->assertSame('elsewhere/app.dump', $this->repository()->key('app.dump', 'elsewhere'));
    }

    public function test_upload_sends_the_dump_and_then_its_manifest(): void
    {
        $disk = Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $dump   = $this->makeDump();
        $result = $this->repository()->upload($dump);

        $this->assertSame('backups/app-2026-01-01_000000.dump', $result['key']);
        $this->assertSame('backups/app-2026-01-01_000000.dump.json', $result['manifest']);
        $this->assertSame(filesize($dump), $result['bytes']);

        $disk->assertExists('backups/app-2026-01-01_000000.dump');
        $disk->assertExists('backups/app-2026-01-01_000000.dump.json');
        $this->assertSame(file_get_contents($dump), $disk->get('backups/app-2026-01-01_000000.dump'));
    }

    public function test_a_dump_with_no_manifest_uploads_but_reports_that_it_cannot_be_verified(): void
    {
        $disk = Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $result = $this->repository()->upload($this->makeDump(withManifest: false));

        $this->assertNull($result['manifest']);
        $disk->assertExists('backups/app-2026-01-01_000000.dump');
        $disk->assertMissing('backups/app-2026-01-01_000000.dump.json');
    }

    public function test_uploading_something_that_is_not_there_fails_loudly(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->expectException(PostgresToolsException::class);
        $this->expectExceptionMessage('Nothing to upload');

        $this->repository()->upload($this->local . '/never-written.dump');
    }

    public function test_listings_are_newest_first_and_ignore_sidecars(): void
    {
        $disk = Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->repository()->upload($this->makeDump('app-2026-01-01_000000.dump'));
        $this->repository()->upload($this->makeDump('app-2026-02-02_000000.dump'));

        // Flysystem's in-memory timestamps can land in the same second, so make the order
        // unambiguous the way the filesystem would.
        touch($disk->path('backups/app-2026-02-02_000000.dump'), time() + 60);

        $files = $this->repository()->all();

        $this->assertCount(2, $files);
        $this->assertSame('app-2026-02-02_000000.dump', $files[0]->name());
        $this->assertSame('app-2026-01-01_000000.dump', $files[1]->name());
    }

    public function test_listed_dumps_carry_their_manifest_and_format(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->repository()->upload($this->makeDump());

        $file = $this->repository()->all()[0];

        $this->assertNotNull($file->manifest);
        $this->assertSame('app_db', $file->manifest->database);
        $this->assertSame('custom', $file->format());
    }

    public function test_the_format_of_an_unverifiable_remote_dump_comes_from_its_name(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->repository()->upload($this->makeDump('app.sql', withManifest: false));

        // No manifest, and the bytes are not local to sniff — the name is all there is.
        $this->assertSame('plain', $this->repository()->all()[0]->format());
    }

    public function test_checksums_are_streamed_and_match_the_local_file(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $dump = $this->makeDump();
        $key  = $this->repository()->upload($dump)['key'];

        $this->assertSame(hash_file('sha256', $dump), $this->repository()->checksum($key));
    }

    public function test_download_brings_back_the_dump_and_its_manifest_and_leaves_no_part_file(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $dump = $this->makeDump();
        $this->repository()->upload($dump);

        $destination = $this->local . '/incoming';
        mkdir($destination);

        $fetched = $this->repository()->download('app-2026-01-01_000000.dump', $destination);

        $this->assertSame($destination . '/app-2026-01-01_000000.dump', $fetched);
        $this->assertFileExists($fetched);
        $this->assertFileExists(BackupFile::manifestPathFor($fetched));
        $this->assertFileDoesNotExist($fetched . '.part');
        $this->assertSame(hash_file('sha256', $dump), hash_file('sha256', $fetched));

        array_map('unlink', glob($destination . '/*') ?: []);
        rmdir($destination);
    }

    public function test_downloading_a_name_that_is_not_there_says_so(): void
    {
        Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->expectException(PostgresToolsException::class);
        $this->expectExceptionMessage('No dump named [missing.dump]');

        $this->repository()->download('missing.dump', $this->local);
    }

    public function test_prune_keeps_the_newest_and_takes_manifests_with_it(): void
    {
        $disk = Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        foreach (['a.dump', 'b.dump', 'c.dump'] as $index => $name) {
            $this->repository()->upload($this->makeDump($name));
            touch($disk->path('backups/' . $name), time() + $index * 60);
        }

        $deleted = $this->repository()->prune(1);

        $this->assertSame(['b.dump', 'a.dump'], $deleted);
        $disk->assertExists('backups/c.dump');
        $disk->assertExists('backups/c.dump.json');
        $disk->assertMissing('backups/a.dump');
        $disk->assertMissing('backups/a.dump.json');
    }

    public function test_prune_with_a_keep_of_zero_deletes_nothing(): void
    {
        $disk = Storage::fake('offsite');
        $this->app['config']->set('postgres-tools.disk', 'offsite');

        $this->repository()->upload($this->makeDump());

        $this->assertSame([], $this->repository()->prune(0));
        $disk->assertExists('backups/app-2026-01-01_000000.dump');
    }
}
