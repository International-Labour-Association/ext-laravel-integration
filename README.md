# Plesk Laravel Toolkit integration with Laravel applications.

[![Apache 2](http://img.shields.io/badge/license-Apache%202-blue.svg)](http://www.apache.org/licenses/LICENSE-2.0)

This package adds Laravel Queues to the Laravel Toolkit extension by Plesk. With the help of this package, one can enable, disable, and configure the Laravel Queue Worker directly from the Plesk UI without having to access the Laravel Application using an SSH console.

### Installation and Configuration

Before you start, take into account the following:

-  Plesk Laravel Toolkit integration package works with Laravel version 7.0.0 and later.
-  Laravel Toolkit integration package supports auto package discovery for Laravel version 7.0 and later. In this case, registration of the service provider is not required.


Here’s how to add Laravel Queues to Plesk Laravel Toolkit:

1. [Integrate the Queue Laravel package into Plesk](https://support.plesk.com/hc/en-us/articles/9574602107410)
2. [Enable the Scheduled Tasks](https://docs.plesk.com/en-US/obsidian/administrator-guide/website-management/laravel-toolkit.80010/#viewing-your-application-s-scheduled-tasks).
3. Enable Queues in Laravel Toolkit. To do so, go to ***Websites & Domains** > your domain > **Manage Laravel Application**, and then on the "Dashboard" tab, click the **Queues** toggle button so that it shows "Enabled".

### Reboot recovery in this fork

This fork targets a single Linux scheduler host. On Laravel versions that support `Event::createMutexNameUsing()` (including Laravel 12), Toolkit worker mutex names include a hash of `/proc/sys/kernel/random/boot_id`. The ID stays stable during a host boot and changes when the kernel reboots. A worker's mutex therefore still prevents overlapping starts during the same boot, while a previous boot's stale mutex cannot block its replacement. Previous mutexes expire normally; this fork does not clear the scheduler cache.

Both multi-queue workers and the legacy single worker use this behavior. Worker commands, queue names, process counts, job retries and the queue/cache connections keep their existing configuration. Other scheduled tasks keep their existing mutexes. Non-Linux platforms and Laravel versions without this API keep the original mutex behavior, without this reboot recovery.

On Linux, the PHP process running the scheduler must be able to read a valid boot ID. Verify this in the Plesk scheduled-task context before installation:

```sh
/path/to/php -r '$id = file_get_contents("/proc/sys/kernel/random/boot_id"); if ($id === false) { exit(1); } echo trim($id).PHP_EOL;'
```

If the boot ID becomes unreadable or malformed, scheduling raises an error instead of selecting a different lock namespace and potentially starting duplicate workers.

For the first installation or a rollback, disable Toolkit's queue workers while leaving the application's scheduled tasks enabled, run `php artisan queue:restart`, and wait for existing workers to finish and exit before changing package versions. Then re-enable the Toolkit workers. Changing mutex names while old workers are still running can start additional workers.

This change covers host reboots. A worker killed during the same boot can still leave its mutex until the existing timeout expires. Container restarts sharing the same host boot ID are not covered. An actual reboot test on the target Plesk server is still required after the controlled deployment.
