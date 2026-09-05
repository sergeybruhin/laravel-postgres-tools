<?php

namespace SergeyBruhin\PostgresTools\Tests;

use Illuminate\Console\Scheduling\Schedule;
use SergeyBruhin\PostgresTools\Providers\PostgresToolsServiceProvider;

/**
 * The built-in schedule. Off by default on purpose: a package that starts dumping your
 * database on a timer the moment it is installed is a surprise, not a convenience.
 */
class ScheduleTest extends TestCase
{
    /** @return array<\Illuminate\Console\Scheduling\Event> */
    private function scheduled(array $config = []): array
    {
        foreach ($config as $key => $value) {
            $this->app['config']->set('postgres-tools.schedule.' . $key, $value);
        }

        // The provider registers its entries during boot, so re-boot it against the config
        // this test just set, then resolve the Schedule to trigger callAfterResolving.
        (new PostgresToolsServiceProvider($this->app))->boot();

        return $this->app->make(Schedule::class)->events();
    }

    private function commands(array $events): array
    {
        return array_values(array_filter(array_map(
            static function ($event): ?string {
                preg_match('/\bpg:[a-z]+.*$/', (string) $event->command, $matches);

                return $matches[0] ?? null;
            },
            $events
        )));
    }

    public function test_nothing_is_scheduled_until_it_is_switched_on(): void
    {
        $this->assertSame([], $this->commands($this->scheduled()));
    }

    public function test_enabling_it_schedules_a_backup_and_a_staleness_check(): void
    {
        $events = $this->scheduled(['enabled' => true]);

        $commands = $this->commands($events);

        $this->assertCount(2, $commands);
        $this->assertStringStartsWith('pg:backup --force --keep=', $commands[0]);
        $this->assertStringStartsWith('pg:check --quiet-ok', $commands[1]);

        $this->assertSame('0 3 * * *', $events[0]->expression);
        $this->assertSame('0 4 * * *', $events[1]->expression);
    }

    public function test_a_slow_dump_can_never_stack_up_behind_itself(): void
    {
        $events = $this->scheduled(['enabled' => true]);

        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->onOneServer);
    }

    public function test_retention_and_upload_come_from_config(): void
    {
        $commands = $this->commands($this->scheduled([
            'enabled' => true,
            'keep'    => 14,
            'upload'  => true,
        ]));

        $this->assertSame('pg:backup --force --keep=14 --upload --keep-remote', $commands[0]);
    }

    public function test_retention_falls_back_to_the_local_keep_setting(): void
    {
        $this->app['config']->set('postgres-tools.keep', 9);

        $commands = $this->commands($this->scheduled(['enabled' => true]));

        $this->assertSame('pg:backup --force --keep=9', $commands[0]);
    }

    public function test_the_check_can_be_switched_off_on_its_own(): void
    {
        $commands = $this->commands($this->scheduled(['enabled' => true, 'check' => false]));

        $this->assertCount(1, $commands);
        $this->assertStringStartsWith('pg:backup', $commands[0]);
    }

    public function test_the_cron_expressions_are_configurable(): void
    {
        $events = $this->scheduled([
            'enabled'    => true,
            'cron'       => '30 2 * * 0',
            'check_cron' => '0 6 * * *',
        ]);

        $this->assertSame('30 2 * * 0', $events[0]->expression);
        $this->assertSame('0 6 * * *', $events[1]->expression);
    }
}
