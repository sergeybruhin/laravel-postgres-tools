<?php

namespace SergeyBruhin\PostgresTools\Providers;

use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use SergeyBruhin\PostgresTools\Console\BackupCommand;
use SergeyBruhin\PostgresTools\Console\InfoCommand;
use SergeyBruhin\PostgresTools\Console\ListBackupsCommand;
use SergeyBruhin\PostgresTools\Console\RestoreCommand;
use SergeyBruhin\PostgresTools\Console\VerifyBackupCommand;
use SergeyBruhin\PostgresTools\Services\BackupRepository;
use SergeyBruhin\PostgresTools\Services\BinaryLocator;
use SergeyBruhin\PostgresTools\Services\ConsistencyChecker;
use SergeyBruhin\PostgresTools\Services\DumpService;
use SergeyBruhin\PostgresTools\Services\EnvironmentReport;
use SergeyBruhin\PostgresTools\Services\ManifestWriter;
use SergeyBruhin\PostgresTools\Services\PgEnvironment;
use SergeyBruhin\PostgresTools\Services\RestoreService;
use SergeyBruhin\PostgresTools\Services\TargetResolver;

class PostgresToolsServiceProvider extends LaravelServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/config.php', 'postgres-tools');

        // Singletons so the binary probe and its version lookups happen once per command
        // rather than once per caller.
        foreach ([
            BinaryLocator::class,
            PgEnvironment::class,
            TargetResolver::class,
            EnvironmentReport::class,
            DumpService::class,
            RestoreService::class,
            ManifestWriter::class,
            ConsistencyChecker::class,
            BackupRepository::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/config.php' => config_path('postgres-tools.php'),
        ], 'config');

        $this->commands([
            InfoCommand::class,
            BackupCommand::class,
            RestoreCommand::class,
            ListBackupsCommand::class,
            VerifyBackupCommand::class,
        ]);
    }
}
