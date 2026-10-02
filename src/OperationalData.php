<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Throwable;

/**
 * What only the server sees (#60), sent in the report's `extra`: failed and
 * waiting jobs, the last scheduler run, free disk space, migrations not run
 * yet and whether config and routes are cached. A value the agent can't
 * read is null; one that doesn't apply (no failed-jobs store, sync queue)
 * is left out.
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
        $failer = $this->app['queue.failer'];

        if (! $failer instanceof NullFailedJobProvider) {
            $report['failed_jobs'] = $this->attempt(fn () => method_exists($failer, 'count') ? (int) $failer->count() : null);
        }

        if ((string) config('queue.connections.'.config('queue.default').'.driver') !== 'sync') {
            // Without pendingSize() (Laravel 10, early 11) there's only size(),
            // which counts delayed and reserved jobs too: unknown then.
            $report['queue_pending'] = $this->attempt(function () {
                $queue = $this->app['queue']->connection();

                return method_exists($queue, 'pendingSize') ? (int) $queue->pendingSize() : null;
            });
        }

        return [
            ...$report,
            'scheduler' => $this->attempt(fn () => $this->app->make(SchedulerHeartbeat::class)->report()),
            'disk' => $this->disk(),
            'migrations_pending' => $this->attempt(fn () => $this->migrationsPending()),
            'config_cached' => $this->app->configurationIsCached(),
            'routes_cached' => $this->app->routesAreCached(),
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
        $migrator = $this->app['migrator'];

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
