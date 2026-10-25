<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\RedisQueue;
use Throwable;

/**
 * The backlog of the queues (#134), sent in `extra.queues`, and Horizon's
 * status in `extra.horizon`: a queue whose worker stopped fails no job, so
 * the agent reports per queue the jobs that are due and since when the
 * oldest of them waits. The queues are the default one of the default
 * connection and the ones Horizon's supervisors work, sync and null left
 * out. Delayed jobs count once they are due, from then on; jobs a worker
 * holds don't count. The database and Redis are asked directly, any other
 * driver through Laravel, where SQS and Beanstalkd know only the number.
 * A job a crashed worker held counts again once its reservation expired,
 * as Laravel hands it out again then.
 */
final class Queues
{
    /** The jobs at the head of a Redis queue read for the oldest one. */
    public const REDIS_PEEK = 10;

    /** The most queues and supervisors sent, as the report schema takes them. */
    public const MAX_QUEUES = 20;

    public const MAX_SUPERVISORS = 20;

    private const MASTERS = 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository';

    private const SUPERVISORS = 'Laravel\\Horizon\\Contracts\\SupervisorRepository';

    /** @var list<object>|null Horizon's supervisors, read once per report */
    private ?array $supervisors = null;

    public function __construct(private readonly Application $app) {}

    /**
     * Each queue with its due jobs and when the oldest of them became due;
     * either is null where the agent can't read it.
     *
     * @return list<array{connection: string, queue: string, driver: string, pending: int|null, oldest_at: string|null}>
     */
    public function queues(): array
    {
        $queues = [];
        $failed = [];

        foreach (array_slice($this->names(), 0, self::MAX_QUEUES) as [$connection, $queue, $driver]) {
            $entry = ['connection' => $connection, 'queue' => $queue, 'driver' => $driver, 'pending' => null, 'oldest_at' => null];

            // A connection that failed once fails for its other queues too,
            // each after the full timeout.
            if (! isset($failed[$connection])) {
                try {
                    [$pending, $oldest] = $this->backlog($connection, $queue);
                    $entry['pending'] = $pending;
                    $entry['oldest_at'] = $oldest === null ? null : date(DATE_ATOM, $oldest);
                } catch (Throwable) {
                    $failed[$connection] = true;
                }
            }

            $queues[] = $entry;
        }

        return $queues;
    }

    /**
     * Whether Horizon runs, is paused or inactive, and its supervisors; null
     * without Horizon.
     *
     * @return array{status: string, supervisors: list<array{name: string, status: string, processes: int}>}|null
     */
    public function horizon(): ?array
    {
        if (! $this->horizonInstalled()) {
            return null;
        }

        $masters = $this->app->make(self::MASTERS)->all();
        $paused = array_filter($masters, fn (object $master) => ($master->status ?? null) === 'paused');
        $supervisors = array_map(fn (object $supervisor) => [
            'name' => mb_substr((string) ($supervisor->name ?? ''), 0, 200),
            'status' => ($supervisor->status ?? null) === 'paused' ? 'paused' : 'running',
            'processes' => (int) array_sum(array_map('intval', (array) ($supervisor->processes ?? []))),
        ], $this->supervisors());

        return [
            'status' => match (true) {
                $masters === [] => 'inactive',
                count($paused) === count($masters) => 'paused',
                default => 'running',
            },
            'supervisors' => array_slice($supervisors, 0, self::MAX_SUPERVISORS),
        ];
    }

    public function horizonInstalled(): bool
    {
        return interface_exists(self::MASTERS) && $this->app->bound(self::MASTERS) && $this->app->bound(self::SUPERVISORS);
    }

    /**
     * The number of due jobs and the timestamp since when the oldest of them
     * waits.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function backlog(string $connection, string $name): array
    {
        $queue = $this->app->make('queue')->connection($connection);
        $now = time();

        if ($queue instanceof DatabaseQueue) {
            $config = (array) config("queue.connections.{$connection}");
            $retryAfter = (int) ($config['retry_after'] ?? 60);
            $row = $queue->getDatabase()->table($config['table'] ?? 'jobs')
                ->where('queue', $name)
                ->where(fn ($due) => $due
                    ->where(fn ($ready) => $ready->whereNull('reserved_at')->where('available_at', '<=', $now))
                    ->orWhere('reserved_at', '<=', $now - $retryAfter))
                ->selectRaw('count(*) as pending, min(case when reserved_at is null then available_at else reserved_at + ? end) as oldest', [$retryAfter])
                ->first();

            $oldest = $row->oldest ?? null;

            return [(int) ($row->pending ?? 0), $oldest === null ? null : (int) $oldest];
        }

        if ($queue instanceof RedisQueue) {
            return $this->redis($queue, $name, $now);
        }

        // Both came with a release of Laravel 11.
        return [
            // @phpstan-ignore function.alreadyNarrowedType
            method_exists($queue, 'pendingSize') ? (int) $queue->pendingSize($name) : null,
            // @phpstan-ignore function.alreadyNarrowedType
            method_exists($queue, 'creationTimeOfOldestPendingJob') ? $this->timestamp($queue->creationTimeOfOldestPendingJob($name)) : null,
        ];
    }

    /**
     * The list holds the jobs ready for a worker, oldest first; delayed jobs
     * wait in a sorted set until a worker moves them over, so the due ones
     * there count too, from the time they became due. A job in the list
     * that was delayed is due from its creation plus its delay; one that
     * was released for another try has no due time in its payload and is
     * passed over, as is every job of a Laravel that doesn't note the delay.
     *
     * @return array{0: int, 1: int|null}
     */
    private function redis(RedisQueue $queue, string $name, int $now): array
    {
        // @phpstan-ignore function.alreadyNarrowedType (older Laravel lacks it)
        $key = method_exists($queue, 'getQueueRedisKey')
            ? (fn () => $this->getQueueRedisKey($name))->call($queue)
            : $queue->getQueue($name);
        $redis = $queue->getConnection();

        $ready = (int) $redis->llen($key);
        $oldest = null;

        foreach ($ready > 0 ? (array) $redis->lrange($key, 0, self::REDIS_PEEK - 1) : [] as $payload) {
            $job = json_decode((string) $payload, true);

            if (is_array($job) && ($job['attempts'] ?? 0) === 0 && array_key_exists('delay', $job) && ($created = $this->timestamp($job['createdAt'] ?? null)) !== null) {
                $oldest = min($oldest ?? PHP_INT_MAX, $created + (int) $job['delay']);
            }
        }

        $due = (int) $redis->zcount("{$key}:delayed", '-inf', (string) $now);
        $earliest = $due > 0 ? $redis->zrangebyscore("{$key}:delayed", '-inf', (string) $now, ['withscores' => true, 'limit' => [0, 1]]) : [];
        $dueSince = is_array($earliest) && $earliest !== [] ? (int) reset($earliest) : null;

        $times = array_filter([$oldest, $dueSince], fn (?int $time) => $time !== null);

        return [$ready + $due, $times === [] ? null : min($times)];
    }

    /**
     * The default queue of the default connection, then the ones Horizon's
     * supervisors are set up for and the ones they work, without repeats.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function names(): array
    {
        $names = [];
        $default = config('queue.default');

        if (is_string($default)) {
            $names[] = [$default, config("queue.connections.{$default}.queue")];
        }

        if ($this->horizonInstalled()) {
            try {
                $names = [...$names, ...$this->horizonQueues()];
            } catch (Throwable) {
                // Horizon's Redis is down: its status says so.
            }
        }

        $queues = [];

        foreach ($names as [$connection, $queue]) {
            $driver = config("queue.connections.{$connection}.driver");
            $queue = is_string($queue) && trim($queue) !== '' ? trim($queue) : 'default';

            if (! is_string($driver) || in_array($driver, ['sync', 'null'], true)) {
                continue;
            }

            $queues["{$connection}\0{$queue}"] = [mb_substr($connection, 0, 100), mb_substr($queue, 0, 200), mb_substr($driver, 0, 50)];
        }

        return array_values($queues);
    }

    /**
     * From Horizon's config for this environment, as it provisions it, and
     * from the supervisors that run, which name theirs as "connection:queue".
     *
     * @return list<array{0: string, 1: string}>
     */
    private function horizonQueues(): array
    {
        $environment = config('horizon.env') ?? $this->app->environment();
        $environments = (array) config('horizon.environments');
        $defaults = (array) config('horizon.defaults');
        $names = [];

        foreach ((array) ($environments[$environment] ?? $environments['*'] ?? []) as $supervisor => $options) {
            $options = array_replace((array) ($defaults[$supervisor] ?? []), (array) $options);
            $queues = is_string($options['queue'] ?? null) ? explode(',', $options['queue']) : (array) ($options['queue'] ?? ['default']);

            foreach ($queues as $queue) {
                $names[] = [(string) ($options['connection'] ?? 'redis'), (string) $queue];
            }
        }

        // Without balancing one process works all queues: "redis:default,emails".
        foreach ($this->supervisors() as $supervisor) {
            foreach (array_keys((array) ($supervisor->processes ?? [])) as $process) {
                if (str_contains((string) $process, ':')) {
                    [$connection, $queues] = explode(':', (string) $process, 2);

                    foreach (explode(',', $queues) as $queue) {
                        $names[] = [$connection, $queue];
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @return list<object>
     */
    private function supervisors(): array
    {
        return $this->supervisors ??= array_values((array) $this->app->make(self::SUPERVISORS)->all());
    }

    private function timestamp(mixed $value): ?int
    {
        return is_numeric($value) && $value > 0 ? (int) $value : null;
    }
}
