<?php

namespace PleskExtLaravel\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use PleskExtLaravel\Providers\ConsoleServiceProvider;
use PleskExtLaravel\Tests\TestCase;

class BootScopedMutexTest extends TestCase
{
    private const FIRST_BOOT = '11111111-1111-4111-8111-111111111111';
    private const SECOND_BOOT = '22222222-2222-4222-8222-222222222222';

    private string $bootIdFile;
    private ConsoleServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['config']->set('cache.default', 'array');
        $this->bootIdFile = tempnam(sys_get_temp_dir(), 'plesk-boot-');
        file_put_contents($this->bootIdFile, self::FIRST_BOOT."\n");

        $this->provider = new class($this->app) extends ConsoleServiceProvider {
            public ?string $testBootIdPath = null;

            protected function bootIdPath(): ?string
            {
                return $this->testBootIdPath;
            }
        };
        $this->provider->testBootIdPath = $this->bootIdFile;

        $this->setPleskEnv([
            'PLESK_EXT_LARAVEL_QUEUE_LIST' => 'default,priority,long',
            'PLESK_EXT_LARAVEL_QUEUE_PRIORITY_COUNT' => '2',
            'PLESK_EXT_LARAVEL_QUEUE_LONG_COUNT' => '2',
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->bootIdFile)) {
            unlink($this->bootIdFile);
        }

        parent::tearDown();
    }

    public function testSameBootPreservesOverlapProtectionForAllWorkers(): void
    {
        $events = $this->workerSchedule()->events();

        $this->assertCount(5, $events);
        $this->assertCount(5, array_unique(array_map(fn ($event) => $event->mutexName(), $events)));

        foreach ($events as $event) {
            $this->assertFalse($event->shouldSkipDueToOverlapping());
        }

        foreach ($this->workerSchedule()->events() as $index => $event) {
            $this->assertSame($events[$index]->mutexName(), $event->mutexName());
            $this->assertTrue($event->shouldSkipDueToOverlapping());
        }
    }

    public function testNewBootStartsWorkersWithoutDeletingPreviousBootLocks(): void
    {
        $previousEvents = $this->workerSchedule()->events();

        foreach ($previousEvents as $event) {
            $this->assertTrue($event->mutex->create($event));
        }

        file_put_contents($this->bootIdFile, self::SECOND_BOOT."\n");

        foreach ($this->workerSchedule()->events() as $index => $event) {
            $previous = $previousEvents[$index];
            $this->assertNotSame($previous->mutexName(), $event->mutexName());
            $this->assertSame($previous->command, $event->command);
            $this->assertTrue($previous->mutex->exists($previous));
            $this->assertFalse($event->shouldSkipDueToOverlapping());
        }
    }

    public function testLegacyWorkerAlsoGetsANewMutexAfterReboot(): void
    {
        $this->clearPleskEnv();
        $this->setPleskEnv(['PLESK_EXT_LARAVEL_QUEUE_WORKER_ENABLED' => 'true']);

        $previous = $this->workerSchedule()->events()[0];
        $this->assertFalse($previous->shouldSkipDueToOverlapping());
        $this->assertTrue($this->workerSchedule()->events()[0]->shouldSkipDueToOverlapping());

        file_put_contents($this->bootIdFile, self::SECOND_BOOT);

        $current = $this->workerSchedule()->events()[0];
        $this->assertNotSame($previous->mutexName(), $current->mutexName());
        $this->assertSame($previous->command, $current->command);
        $this->assertFalse($current->shouldSkipDueToOverlapping());
    }

    public function testScheduleFinishReleasesOnlyTheCurrentBootWorkerMutex(): void
    {
        $previous = $this->workerSchedule()->events()[0];
        $this->assertTrue($previous->mutex->create($previous));

        file_put_contents($this->bootIdFile, self::SECOND_BOOT);
        $schedule = $this->workerSchedule();
        $current = $schedule->events()[0];
        $this->assertTrue($current->mutex->create($current));
        $this->app->instance(Schedule::class, $schedule);

        $this->artisan('schedule:finish', ['id' => $current->mutexName(), 'code' => 0])
            ->assertExitCode(0);

        $this->assertFalse($current->mutex->exists($current));
        $this->assertTrue($previous->mutex->exists($previous));
    }

    public function testUnrelatedScheduledCommandsKeepTheirMutexNames(): void
    {
        $schedule = new Schedule;
        $unrelated = $schedule->command('inspire')->withoutOverlapping()->everyMinute();
        $originalName = $unrelated->mutexName();

        $this->provider->schedule($schedule);

        $this->assertSame($originalName, $unrelated->mutexName());
    }

    public function testUnreadableBootIdStopsSchedulingInsteadOfChangingToFallbackLocks(): void
    {
        unlink($this->bootIdFile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read a valid Linux boot ID');

        $this->workerSchedule();
    }

    public function testMalformedBootIdIsRejected(): void
    {
        file_put_contents($this->bootIdFile, 'not-a-boot-id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read a valid Linux boot ID');

        $this->workerSchedule();
    }

    public function testPlatformsWithoutABootIdSourceKeepExistingMutexBehavior(): void
    {
        $this->provider->testBootIdPath = null;
        $previous = $this->workerSchedule()->events()[0];
        $this->assertTrue($previous->mutex->create($previous));

        file_put_contents($this->bootIdFile, self::SECOND_BOOT);
        $current = $this->workerSchedule()->events()[0];

        $this->assertSame($previous->mutexName(), $current->mutexName());
        $this->assertTrue($current->shouldSkipDueToOverlapping());
    }

    public function testDisabledQueuesDoNotRequireABootId(): void
    {
        unlink($this->bootIdFile);
        $this->clearPleskEnv();
        $this->setPleskEnv(['PLESK_EXT_LARAVEL_QUEUE_LIST' => '']);

        $this->assertCount(0, $this->workerSchedule()->events());
    }

    private function workerSchedule(): Schedule
    {
        $schedule = new Schedule;
        $this->provider->schedule($schedule);

        return $schedule;
    }
}
