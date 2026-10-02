<?php

namespace StackMonitor\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Laravel keeps no record of when the scheduler last ran (#60), so the agent
 * notes every start of `schedule:run` in the app's cache, together with the
 * number of tasks the app schedules: an app without tasks needs no scheduler.
 */
final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'stackmonitor-agent:scheduler';

    public function __construct(private readonly Application $app) {}

    /**
     * Called when `schedule:run` starts; a broken cache must never stop the
     * app's own tasks, so it fails silently.
     */
    public function record(): void
    {
        try {
            $this->app['cache']->forever(self::CACHE_KEY, [
                'at' => now()->getTimestamp(),
                'tasks' => count($this->app->make(Schedule::class)->events()),
            ]);
        } catch (Throwable) {
            // Nothing to report then; the dashboard sees the run as missing.
        }
    }

    /**
     * @return array{last_run_at: string, tasks: int}|null null before the first run seen
     */
    public function report(): ?array
    {
        $run = $this->app['cache']->get(self::CACHE_KEY);

        if (! is_array($run) || ! is_int($run['at'] ?? null) || ! is_int($run['tasks'] ?? null)) {
            return null;
        }

        return ['last_run_at' => date(DATE_ATOM, $run['at']), 'tasks' => $run['tasks']];
    }
}
