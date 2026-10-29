<?php

namespace PleskExtLaravel\Providers;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use PleskExtLaravel\Console\Commands\ConfigSource;
use PleskExtLaravel\Console\Commands\ListEnv;
use PleskExtLaravel\PleskEnv;

class ConsoleServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->commands([
            ListEnv::class,
            ConfigSource::class,
        ]);
    }

    public function boot()
    {
        $this->app->resolving(Schedule::class, function ($schedule) {
            $this->schedule($schedule);
        });
    }

    /**
     * Register the queue workers, supporting both the multi-queue scheme
     * (PLESK_EXT_LARAVEL_QUEUE_LIST) and the legacy single-worker variables.
     */
    public function schedule(Schedule $schedule)
    {
        if (PleskEnv::isMultiQueue()) {
            $this->scheduleQueues($schedule);

            return;
        }

        $this->scheduleLegacyWorker($schedule);
    }

    /**
     * Schedule one "queue:work" per parallel worker for every enabled queue.
     *
     * Each worker gets a unique --name so its command string (and therefore its
     * withoutOverlapping mutex) is distinct, allowing COUNT workers to run in
     * parallel. runInBackground() lets a single schedule:run tick launch them
     * all without running them sequentially.
     */
    private function scheduleQueues(Schedule $schedule): void
    {
        foreach (PleskEnv::queues() as $queue) {
            if (!PleskEnv::isEnabled($queue)) {
                continue;
            }

            $flags = PleskEnv::flags($queue);
            $count = PleskEnv::count($queue);

            for ($i = 0; $i < $count; $i++) {
                $command = array_merge(
                    ['queue:work'],
                    $flags,
                    ["--queue={$queue}", "--name=queue-worker-{$queue}-{$i}"]
                );

                $event = $schedule->command(implode(' ', $command))
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->everyMinute();

                $this->scopeWorkerMutexToBoot($event);
            }
        }
    }

    /**
     * Schedule the legacy single worker (no --queue, i.e. the default queue),
     * matching the behaviour of previous versions.
     */
    private function scheduleLegacyWorker(Schedule $schedule): void
    {
        if (!PleskEnv::legacyEnabled()) {
            return;
        }

        $command = array_merge(['queue:work'], PleskEnv::legacyFlags());

        $event = $schedule->command(implode(' ', $command))
            ->withoutOverlapping()
            ->everyMinute();

        $this->scopeWorkerMutexToBoot($event);
    }

    private function scopeWorkerMutexToBoot(Event $event): void
    {
        $path = $this->bootIdPath();

        if ($path === null || !method_exists($event, 'createMutexNameUsing')) {
            return;
        }

        $bootId = @file_get_contents($path);

        if ($bootId === false || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', trim($bootId))) {
            throw new \RuntimeException('Cannot read a valid Linux boot ID at '.$path.'. Queue workers were not scheduled.');
        }

        // A new boot must not inherit worker locks from processes that no longer exist.
        $event->createMutexNameUsing($event->mutexName().'-boot-'.hash('sha256', strtolower(trim($bootId))));
    }

    protected function bootIdPath(): ?string
    {
        return PHP_OS_FAMILY === 'Linux' ? '/proc/sys/kernel/random/boot_id' : null;
    }
}
