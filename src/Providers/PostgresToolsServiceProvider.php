<?php

namespace SergeyBruhin\PostgresTools\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use SergeyBruhin\PostgresTools\Console\BackupCommand;
use SergeyBruhin\PostgresTools\Console\CheckCommand;
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
use SergeyBruhin\PostgresTools\Services\RemoteRepository;
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
            RemoteRepository::class,
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
            CheckCommand::class,
        ]);

        $this->schedule();
    }

    /**
     * Register the built-in schedule, when it is switched on.
     *
     * callAfterResolving rather than resolving the Schedule here: on Laravel 11 and 12 the
     * scheduler is built from routes/console.php and resolving it during boot would force
     * it into existence on every console command, including the ones that must not touch
     * it. This way the entries are added only if something actually asks for a schedule.
     */
    private function schedule(): void
    {
        if (!(bool) $this->app['config']->get('postgres-tools.schedule.enabled', false)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config   = $this->app['config'];
            $timezone = $config->get('postgres-tools.schedule.timezone');

            $keep = $config->get('postgres-tools.schedule.keep')
                ?? $config->get('postgres-tools.keep', 7);

            $backup = 'pg:backup --force --keep=' . (int) $keep
                . ($config->get('postgres-tools.schedule.upload') ? ' --upload --keep-remote' : '');

            $event = $schedule->command($backup)
                ->cron((string) $config->get('postgres-tools.schedule.cron', '0 3 * * *'))
                // A dump slower than the interval must not start a second one on top of the
                // first: two pg_dumps against the same server is how a backup window becomes
                // an outage.
                ->withoutOverlapping(60)
                ->onOneServer();

            $timezone && $event->timezone($timezone);

            if (!(bool) $config->get('postgres-tools.schedule.check', true)) {
                return;
            }

            $check = $schedule->command('pg:check --quiet-ok')
                ->cron((string) $config->get('postgres-tools.schedule.check_cron', '0 4 * * *'))
                ->onOneServer();

            $timezone && $check->timezone($timezone);
        });
    }
}
