<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Throwable;

/**
 * What only the server sees (#60), sent in the report's `extra`: failed and
 * waiting jobs, the last scheduler run, free disk space, migrations not run
 * yet, whether config and routes are cached, the last backup (#64), the
 * PHP settings (#140), every scheduled task with its last run (#138), the
 * failed logins per hour (#145), PHP files where only uploads belong
 * (#144), the last deploy (#150), the size of the database (#149) and the
 * cache store with whether spatie/laravel-responsecache caches pages (#155)
 * and whether the services the app depends on answer (#156), and the PHP
 * code the dashboard can't download itself for the PHP upgrade forecast
 * (#159), the backlog of the queues and Horizon's status (#134). A value
 * the agent can't read is null; one that doesn't apply (no failed-jobs
 * store, sync queue, no Horizon) is left out.
 */
final class OperationalData
{
    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $report = [];
        /** @var FailedJobProviderInterface $failer whichever the app configured */
        $failer = $this->app->make('queue.failer');

        if (! $failer instanceof NullFailedJobProvider) {
            $report['failed_jobs'] = $this->attempt(fn () => method_exists($failer, 'count') ? Value::int($failer->count()) : null);
        }

        $queues = $this->app->make(Queues::class);
        $backlog = $this->attempt(fn () => $queues->queues());

        if ($backlog !== []) {
            $report['queues'] = $backlog;
        }

        // For dashboards before #134: the due jobs of the default queue.
        if (! in_array(Value::string(config('queue.connections.'.Value::string(config('queue.default')).'.driver')), ['sync', 'null'], true)) {
            $report['queue_pending'] = $backlog[0]['pending'] ?? null;
        }

        if ($queues->horizonInstalled()) {
            $report['horizon'] = $this->attempt(fn () => $queues->horizon());
        }

        return [
            ...$report,
            'scheduler' => $this->attempt(fn () => $this->app->make(SchedulerHeartbeat::class)->report()),
            'disk' => $this->disk(),
            'migrations_pending' => $this->attempt(fn () => $this->migrationsPending()),
            'config_cached' => $this->app->configurationIsCached(),
            'routes_cached' => $this->app->routesAreCached(),
            'backup' => $this->attempt(fn () => $this->app->make(Backups::class)->report()),
            'php_config' => $this->attempt(fn () => $this->app->make(PhpConfig::class)->report()),
            'scheduled_tasks' => $this->attempt(fn () => $this->app->make(ScheduledTasks::class)->report()),
            'failed_logins' => $this->attempt(fn () => $this->app->make(FailedLogins::class)->report()),
            'suspicious_files' => $this->attempt(fn () => $this->app->make(SuspiciousFiles::class)->report()),
            'deploy' => $this->attempt(fn () => (new Deploys($this->app, $this->basePath))->report()),
            'database' => $this->attempt(fn () => $this->app->make(DatabaseSize::class)->report()),
            'cache' => $this->cache(),
            'services' => $this->attempt(fn () => $this->app->make(Services::class)->report()),
            'php_code' => $this->attempt(fn () => (new PhpCode($this->app, $this->basePath))->report()),
        ];
    }

    /**
     * The default cache store and its driver, and spatie/laravel-responsecache:
     * null when it isn't installed, else whether it is on. The one package
     * that caches whole pages in Laravel; the dashboard shows it next to the
     * page cache it sees from outside.
     *
     * @return array{store: string|null, driver: string|null, response_cache: bool|null}
     */
    private function cache(): array
    {
        $store = config('cache.default');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;

        return [
            'store' => is_string($store) && $store !== '' ? $store : null,
            'driver' => is_string($driver) && $driver !== '' ? $driver : null,
            'response_cache' => class_exists('Spatie\\ResponseCache\\ResponseCache') ? (bool) config('responsecache.enabled', true) : null,
        ];
    }

    /**
     * Of the volume the app lives on; on shared hosting that can be the whole
     * server's disk, not the account's quota.
     *
     * @return array{free: int, total: int}|null
     */
    private function disk(): ?array
    {
        $path = $this->basePath ?? $this->app->basePath();

        if (! function_exists('disk_free_space') || ! function_exists('disk_total_space')) {
            return null;
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return is_float($free) && is_float($total) && $total > 0 ? ['free' => (int) $free, 'total' => (int) $total] : null;
    }

    /**
     * The migration files of the app and its packages that haven't run; null
     * without a migrations table.
     */
    private function migrationsPending(): ?int
    {
        $migrator = $this->app->make('migrator');

        if (! $migrator->repositoryExists()) {
            return null;
        }

        $files = $migrator->getMigrationFiles([$this->app->databasePath('migrations'), ...$migrator->paths()]);

        return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    /**
     * A failing database, queue or cache leaves the value unknown instead of
     * breaking the whole report.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T|null
     */
    private function attempt(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
