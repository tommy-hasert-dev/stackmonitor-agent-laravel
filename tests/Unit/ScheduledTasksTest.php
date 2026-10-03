<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\ScheduledTasks;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function scheduledTasks(): array
{
    return app(ScheduledTasks::class)->report();
}

function scheduleRunStarts(): void
{
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
}

it('lists every scheduled task with its schedule, before any run', function () {
    config(['app.timezone' => 'UTC']);
    app(Schedule::class)->command('inspire --quiet')->dailyAt('03:00')->timezone('Europe/Berlin');
    app(Schedule::class)->call(fn () => null)->name('Bereinigen')->everyFiveMinutes();
    app(Schedule::class)->exec('rm -rf /tmp/cache')->hourly()->runInBackground();

    $tasks = scheduledTasks();

    expect($tasks)->toHaveCount(3)
        ->and($tasks[0])->toMatchArray([
            'id' => sha1('inspire --quiet'), 'command' => 'inspire --quiet', 'description' => null,
            'expression' => '0 3 * * *', 'timezone' => 'Europe/Berlin', 'background' => false,
            'since' => null, 'last_run' => null, 'last_skipped_at' => null, 'durations' => [],
        ])
        ->and($tasks[1])->toMatchArray(['command' => 'Bereinigen', 'expression' => '*/5 * * * *', 'timezone' => 'UTC'])
        ->and($tasks[2])->toMatchArray(['command' => 'rm -rf /tmp/cache', 'background' => true]);
});

it('knows a task by its command, not its schedule, and numbers a command scheduled twice', function () {
    app(Schedule::class)->command('inspire')->hourly();
    app(Schedule::class)->command('inspire')->daily();

    expect(array_column(scheduledTasks(), 'id'))->toBe([sha1('inspire'), sha1('inspire#2')]);
});

it('leaves out tasks for other environments', function () {
    app(Schedule::class)->command('inspire')->hourly()->environments(['staging']);
    app(Schedule::class)->command('about')->hourly();

    expect(array_column(scheduledTasks(), 'command'))->toBe(['about']);
});

it('notes since when a task is scheduled, from the first scheduler run that saw it', function () {
    app(Schedule::class)->command('inspire')->hourly();
    $this->travelTo('2026-10-02 10:00:00');
    scheduleRunStarts();

    app(Schedule::class)->command('about')->hourly();
    $this->travelTo('2026-10-03 08:00:00');
    scheduleRunStarts();

    expect(array_column(scheduledTasks(), 'since'))->toBe(['2026-10-02T10:00:00+00:00', '2026-10-03T08:00:00+00:00']);
});

it('records a successful run with its duration and keeps the recent ones', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();
    $this->travelTo('2026-10-03 09:00:00');

    foreach ([1.5, 2.25, 3.0] as $runtime) {
        event(new ScheduledTaskStarting($task));
        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, $runtime));
    }

    expect(scheduledTasks()[0]['last_run'])->toMatchArray([
        'started_at' => '2026-10-03T09:00:00+00:00', 'status' => 'ok', 'duration' => 3.0, 'exit_code' => 0, 'error' => null,
    ])->and(scheduledTasks()[0]['durations'])->toBe([1.5, 2.25, 3.0]);
});

it('keeps only the last ten durations', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();

    foreach (range(1, 12) as $runtime) {
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, (float) $runtime));
    }

    expect(scheduledTasks()[0]['durations'])->toBe(array_map('floatval', range(3, 12)));
});

it('shows a task as running between start and end', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();

    event(new ScheduledTaskStarting($task));

    expect(scheduledTasks()[0]['last_run'])->toMatchArray(['status' => 'running', 'finished_at' => null, 'duration' => null]);
});

it('records a command failing with its exit code, without counting its duration', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 3;
    event(new ScheduledTaskFinished($task, 4.0));
    event(new ScheduledTaskFailed($task, new Exception("Scheduled command [{$task->command}] failed with exit code [3].")));

    expect(scheduledTasks()[0]['last_run'])->toMatchArray(['status' => 'failed', 'exit_code' => 3, 'duration' => 4.0, 'error' => null])
        ->and(scheduledTasks()[0]['durations'])->toBe([]);
});

it('records a task throwing with a short error message', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('Export')->hourly();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException(str_repeat('x', 400))));

    $run = scheduledTasks()[0]['last_run'];

    expect($run)->toMatchArray(['status' => 'failed', 'exit_code' => 1])
        ->and($run['error'])->toHaveLength(ScheduledTasks::MAX_ERROR)
        ->and($run['finished_at'])->not->toBeNull();
});

it('ends a background task only with schedule:finish', function () {
    $task = app(Schedule::class)->command('inspire')->hourly()->runInBackground();
    $this->travelTo('2026-10-03 09:00:00');

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.01));
    expect(scheduledTasks()[0]['last_run']['status'])->toBe('running');

    $this->travelTo('2026-10-03 09:05:00');
    $task->exitCode = 0;
    event(new ScheduledBackgroundTaskFinished($task));

    $run = scheduledTasks()[0]['last_run'];
    expect($run)->toMatchArray(['status' => 'ok', 'exit_code' => 0, 'finished_at' => '2026-10-03T09:05:00+00:00'])
        ->and($run['duration'])->toBeGreaterThan(0.0);
});

it('reports a background task ending with an error code as failed', function () {
    $task = app(Schedule::class)->command('inspire')->hourly()->runInBackground();

    event(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    event(new ScheduledBackgroundTaskFinished($task));

    expect(scheduledTasks()[0]['last_run'])->toMatchArray(['status' => 'failed', 'exit_code' => 1]);
});

it('notes skipped runs without touching the last run', function () {
    $task = app(Schedule::class)->command('inspire')->hourly()->withoutOverlapping();
    $this->travelTo('2026-10-03 09:00:00');
    event(new ScheduledTaskStarting($task));

    $this->travelTo('2026-10-03 10:00:00');
    event(new ScheduledTaskSkipped($task));

    expect(scheduledTasks()[0])->toMatchArray(['last_skipped_at' => '2026-10-03T10:00:00+00:00'])
        ->and(scheduledTasks()[0]['last_run']['status'])->toBe('running');
});

it('follows real scheduler runs', function () {
    app(Schedule::class)->call(fn () => null)->name('Gut')->everyMinute();
    app(Schedule::class)->call(fn () => throw new RuntimeException('Export kaputt'))->name('Kaputt')->everyMinute();
    app(Schedule::class)->call(fn () => null)->name('Nie')->everyMinute()->when(false);

    $this->artisan('schedule:run');

    $tasks = collect(scheduledTasks())->keyBy('command');
    expect($tasks['Gut']['last_run']['status'])->toBe('ok')
        ->and($tasks['Kaputt']['last_run'])->toMatchArray(['status' => 'failed', 'error' => 'Export kaputt'])
        ->and($tasks['Nie']['last_run'])->toBeNull()
        ->and($tasks['Nie']['last_skipped_at'])->not->toBeNull();
});

it('never lets a broken cache break the scheduler', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'redis', 'connection' => 'missing']]);

    event(new ScheduledTaskStarting($task));
    scheduleRunStarts();

    expect(true)->toBeTrue();
});

it('matches the shared schema', function () {
    $task = app(Schedule::class)->command('inspire')->hourly();
    app(Schedule::class)->call(fn () => null)->hourly();
    scheduleRunStarts();
    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 1.0));

    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode(scheduledTasks())), $schema->properties->extra->properties->scheduled_tasks);

    expect($result->isValid())->toBeTrue();
});
