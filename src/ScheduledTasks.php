<?php

namespace StackMonitor\Agent;

use DateTimeZone;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Every task of the app's scheduler with its last run (#138): Laravel keeps
 * no record of it, so the agent notes start, end, result and the recent
 * durations of each task in the app's cache, and since when it has been
 * scheduled. The dashboard works out from that and the cron expression
 * whether a run was missed, failed or took unusually long.
 *
 * A task is known by its command with arguments (a callback by its
 * description), not by its cron expression: moving it to another time keeps
 * its history. Background tasks end with `schedule:finish`, not right away.
 */
final class ScheduledTasks
{
    public const CACHE_PREFIX = 'stackmonitor-agent:task:';

    /** Since when each task has been scheduled, by id. */
    public const SINCE_KEY = 'stackmonitor-agent:tasks-since';

    /** Durations of successful runs kept per task, for the dashboard's average. */
    public const DURATIONS = 10;

    /** Tasks reported at most. */
    public const MAX_TASKS = 100;

    /** Error messages are cut to this many characters. */
    public const MAX_ERROR = 300;

    public function __construct(private readonly Application $app) {}

    /**
     * Notes new tasks with the time they first showed up, when `schedule:run`
     * starts; written only when the schedule changed.
     */
    public function recordSchedule(): void
    {
        $this->quietly(function () {
            $since = $this->cache()->get(self::SINCE_KEY);
            $since = is_array($since) ? $since : [];
            $now = now()->getTimestamp();
            $current = [];

            foreach (array_keys($this->tasks()) as $id) {
                $current[$id] = is_int($since[$id] ?? null) ? $since[$id] : $now;
            }

            if ($current !== $since) {
                $this->cache()->forever(self::SINCE_KEY, $current);
            }
        });
    }

    /**
     * Keeps what the scheduler reports about one task. A broken cache must
     * never stop the app's own tasks, so it fails silently.
     */
    public function record(object $event): void
    {
        $this->quietly(function () use ($event) {
            $task = $event->task;
            $id = $this->idOf($task);
            $run = $this->cache()->get(self::CACHE_PREFIX.$id);
            $run = is_array($run) ? $run : [];
            $now = now()->getPreciseTimestamp(3) / 1000;

            $run = match (true) {
                $event instanceof ScheduledTaskStarting => [
                    ...$run, 'started_at' => $now, 'finished_at' => null, 'status' => 'running',
                    'duration' => null, 'exit_code' => null, 'error' => null,
                ],
                // A background task only started; schedule:finish ends it.
                $event instanceof ScheduledTaskFinished && $task->runInBackground => null,
                $event instanceof ScheduledTaskFinished => $this->finish($run, $now, (float) $event->runtime, $task->exitCode),
                $event instanceof ScheduledBackgroundTaskFinished => $this->finish(
                    $run, $now, is_float($run['started_at'] ?? null) ? $now - $run['started_at'] : null, $task->exitCode,
                ),
                // After a non-zero exit code Finished came first and kept it;
                // the exception only repeats that code.
                $event instanceof ScheduledTaskFailed && ($run['status'] ?? null) !== 'running' => [...$run, 'status' => 'failed'],
                $event instanceof ScheduledTaskFailed => [
                    ...$this->finish($run, $now, is_float($run['started_at'] ?? null) ? $now - $run['started_at'] : null, $task->exitCode ?: 1),
                    'status' => 'failed',
                    'error' => mb_substr(trim($event->exception->getMessage()), 0, self::MAX_ERROR) ?: null,
                ],
                $event instanceof ScheduledTaskSkipped => [...$run, 'skipped_at' => $now],
                default => null,
            };

            if ($run !== null) {
                $this->cache()->forever(self::CACHE_PREFIX.$id, $run);
            }
        });
    }

    /**
     * The tasks that run in this environment with their last run, in the
     * order the app schedules them.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $since = $this->cache()->get(self::SINCE_KEY);
        $report = [];

        foreach (array_slice($this->tasks(), 0, self::MAX_TASKS, true) as $id => $task) {
            $run = $this->cache()->get(self::CACHE_PREFIX.$id);
            $run = is_array($run) ? $run : [];
            $timezone = $task->timezone instanceof DateTimeZone ? $task->timezone->getName() : $task->timezone;
            $command = $this->command($task);

            $report[] = [
                'id' => $id,
                'command' => mb_substr($command, 0, 2000),
                'description' => is_string($task->description) && $task->description !== $command ? mb_substr($task->description, 0, 500) : null,
                'expression' => $task->getExpression(),
                'repeat_seconds' => isset($task->repeatSeconds) && is_int($task->repeatSeconds) ? $task->repeatSeconds : null,
                'timezone' => is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC'),
                'background' => (bool) $task->runInBackground,
                'since' => is_int($since[$id] ?? null) ? date(DATE_ATOM, $since[$id]) : null,
                'last_run' => is_float($run['started_at'] ?? null) && is_string($run['status'] ?? null) ? [
                    'started_at' => date(DATE_ATOM, (int) $run['started_at']),
                    'finished_at' => is_float($run['finished_at'] ?? null) ? date(DATE_ATOM, (int) $run['finished_at']) : null,
                    'status' => $run['status'],
                    'duration' => is_float($run['duration'] ?? null) ? round($run['duration'], 2) : null,
                    'exit_code' => is_int($run['exit_code'] ?? null) ? $run['exit_code'] : null,
                    'error' => is_string($run['error'] ?? null) ? $run['error'] : null,
                ] : null,
                'last_skipped_at' => is_float($run['skipped_at'] ?? null) ? date(DATE_ATOM, (int) $run['skipped_at']) : null,
                'durations' => array_values(array_filter((array) ($run['durations'] ?? []), 'is_float')),
            ];
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function finish(array $run, float $now, ?float $duration, mixed $exitCode): array
    {
        $exitCode = is_numeric($exitCode) ? (int) $exitCode : null;
        $duration = $duration === null ? null : max(0.0, $duration);
        $ok = $exitCode === null || $exitCode === 0;
        $durations = array_values(array_filter((array) ($run['durations'] ?? []), 'is_float'));

        if ($ok && $duration !== null) {
            $durations = array_slice([...$durations, round($duration, 2)], -self::DURATIONS);
        }

        return [
            ...$run,
            'started_at' => is_float($run['started_at'] ?? null) ? $run['started_at'] : $now - ($duration ?? 0.0),
            'finished_at' => $now,
            'status' => $ok ? 'ok' : 'failed',
            'duration' => $duration === null ? null : round($duration, 2),
            'exit_code' => $exitCode,
            'error' => null,
            'durations' => $durations,
        ];
    }

    /**
     * The tasks that run in this environment by id; a command scheduled
     * twice gets a number from its second time on.
     *
     * @return array<string, Event>
     */
    private function tasks(): array
    {
        $environment = $this->app->environment();
        $tasks = [];

        foreach ($this->app->make(Schedule::class)->events() as $task) {
            if (! $task->runsInEnvironment($environment)) {
                continue;
            }

            $key = $this->command($task);
            $id = sha1($key);

            for ($n = 2; isset($tasks[$id]); $n++) {
                $id = sha1("{$key}#{$n}");
            }

            $tasks[$id] = $task;
        }

        return $tasks;
    }

    /**
     * The id of a task the scheduler just ran: the same instance from the
     * schedule, or by its command for one that isn't there (any more).
     */
    private function idOf(Event $task): string
    {
        foreach ($this->tasks() as $id => $scheduled) {
            if ($scheduled === $task) {
                return $id;
            }
        }

        return sha1($this->command($task));
    }

    /**
     * The command without the PHP and artisan paths, which differ between
     * servers, e.g. `backup:run --only-db`; a callback's description.
     */
    private function command(Event $task): string
    {
        if ($task instanceof CallbackEvent || ! is_string($task->command)) {
            return $task->getSummaryForDisplay();
        }

        $prefix = trim(Artisan::formatCommandString(''));

        return str_starts_with($task->command, $prefix.' ')
            ? trim(substr($task->command, strlen($prefix)))
            : trim($task->command);
    }

    private function cache(): Repository
    {
        return $this->app['cache']->store();
    }

    private function quietly(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // Nothing recorded then; the dashboard sees the run as missing.
        }
    }
}
