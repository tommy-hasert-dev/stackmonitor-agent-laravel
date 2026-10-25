<?php

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\Queues;

require_once __DIR__.'/../Stubs/horizon.php';

function queuesReport(): array
{
    return app(OperationalData::class)->report();
}

function jobsTable(): void
{
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function job(string $queue, int $availableAt, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt, 'available_at' => $availableAt, 'created_at' => $availableAt]);
}

/**
 * Horizon with the given master supervisors and supervisors.
 *
 * @param  list<object>  $masters
 * @param  list<object>  $supervisors
 */
function horizon(array $masters, array $supervisors): void
{
    app()->instance(MasterSupervisorRepository::class, new class($masters) implements MasterSupervisorRepository
    {
        public function __construct(private array $masters) {}

        public function all()
        {
            return $this->masters;
        }
    });
    app()->instance(SupervisorRepository::class, new class($supervisors) implements SupervisorRepository
    {
        public function __construct(private array $supervisors) {}

        public function all()
        {
            return $this->supervisors;
        }
    });
}

/**
 * A Redis connection "redis" holding the given list and delayed jobs per key.
 *
 * @param  array<string, list<string>>  $lists
 * @param  array<string, array<string, int>>  $delayed
 */
function fakeRedis(array $lists, array $delayed = []): void
{
    $redis = new class($lists, $delayed)
    {
        public function __construct(private array $lists, private array $delayed) {}

        public function isCluster()
        {
            return false;
        }

        public function llen($key)
        {
            return count($this->lists[$key] ?? []);
        }

        public function lrange($key, $start, $stop)
        {
            return array_slice($this->lists[$key] ?? [], $start, $stop - $start + 1);
        }

        public function zcount($key, $min, $max)
        {
            return count(array_filter($this->delayed[$key] ?? [], fn ($score) => $score <= (int) $max));
        }

        public function zrangebyscore($key, $min, $max, $options = [])
        {
            $due = array_filter($this->delayed[$key] ?? [], fn ($score) => $score <= (int) $max);
            asort($due);

            return array_slice($due, 0, 1, true);
        }
    };
    $factory = new class($redis) implements Factory
    {
        public function __construct(private object $redis) {}

        public function connection($name = null)
        {
            return $this->redis;
        }
    };
    config(['queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default']]);
    app('queue')->addConnector('redis', fn () => new class($factory) implements ConnectorInterface
    {
        public function __construct(private Factory $factory) {}

        public function connect(array $config)
        {
            return new RedisQueue($this->factory, $config['queue']);
        }
    });
}

/** A job in a Redis list as Laravel writes it. */
function redisJob(int $createdAt, ?int $delay = null, int $attempts = 0): string
{
    return json_encode(['uuid' => bin2hex(random_bytes(4)), 'attempts' => $attempts, 'delay' => $delay, 'createdAt' => $createdAt]);
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
    app()->instance('queue.failer', new NullFailedJobProvider);
});

it('reports the due jobs of the default queue and since when the oldest waits', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
    jobsTable();
    $now = time();
    job('default', $now - 600);
    job('default', $now - 60);
    // Delayed, not due yet.
    job('default', $now + 3600);
    // A worker holds it.
    job('default', $now - 7200, reservedAt: $now - 10);
    // A crashed worker held it; due again once the reservation expired.
    job('default', $now - 7200, reservedAt: $now - 4000);
    job('emails', $now - 9000);

    expect(queuesReport())->toMatchArray([
        'queues' => [['connection' => 'database', 'queue' => 'default', 'driver' => 'database', 'pending' => 3, 'oldest_at' => date(DATE_ATOM, $now - 4000 + 90)]],
        'queue_pending' => 3,
    ]);
});

it('takes retry_after from the .env as a number and an invalid one as the default', function (mixed $retryAfter, int $expected) {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => $retryAfter]]);
    jobsTable();
    $now = time();
    job('default', $now - 7200, reservedAt: $now - 4000);

    expect(queuesReport()['queues'][0])->toMatchArray(['pending' => 1, 'oldest_at' => date(DATE_ATOM, $now - 4000 + $expected)]);
})->with([
    'string' => ['90', 90],
    'not a number' => ['soon', 60],
]);

it('reports an empty queue without an oldest job', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']]);
    jobsTable();

    expect(queuesReport()['queues'][0])->toMatchArray(['pending' => 0, 'oldest_at' => null]);
});

it('leaves out the queues and Horizon for the sync driver without Horizon', function () {
    expect(queuesReport())->not->toHaveKeys(['queues', 'queue_pending', 'horizon']);
});

it('counts the delayed Redis jobs once they are due, from then on', function () {
    config(['queue.default' => 'redis']);
    $now = time();
    fakeRedis(
        ['queues:default' => [redisJob($now - 300), redisJob($now - 5)]],
        ['queues:default:delayed' => ['due' => $now - 1200, 'later' => $now + 600]],
    );

    expect(queuesReport()['queues'])->toBe([['connection' => 'redis', 'queue' => 'default', 'driver' => 'redis', 'pending' => 3, 'oldest_at' => date(DATE_ATOM, $now - 1200)]]);
});

it('takes a delayed Redis job as due from its delay and passes over released ones', function () {
    config(['queue.default' => 'redis']);
    $now = time();
    fakeRedis(['queues:default' => [
        // Released for another try: no due time to go by.
        redisJob($now - 7200, attempts: 1),
        // Delayed by an hour, due for 10 minutes.
        redisJob($now - 4200, delay: 3600),
        redisJob($now - 60),
    ]]);

    expect(queuesReport()['queues'][0])->toMatchArray(['pending' => 3, 'oldest_at' => date(DATE_ATOM, $now - 600)]);
});

it('knows no age on a Laravel that does not note the delay', function () {
    config(['queue.default' => 'redis']);
    fakeRedis(['queues:default' => [json_encode(['createdAt' => time() - 7200])]]);

    expect(queuesReport()['queues'][0])->toMatchArray(['pending' => 1, 'oldest_at' => null]);
});

it('reports only the number where the driver knows no age', function () {
    config(['queue.default' => 'sqs', 'queue.connections.sqs' => ['driver' => 'sqs', 'queue' => 'jobs']]);
    app('queue')->addConnector('sqs', fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new class
            {
                public function setConnectionName($name)
                {
                    return $this;
                }

                public function setContainer($container): void {}

                public function pendingSize($queue = null)
                {
                    return 42;
                }

                public function creationTimeOfOldestPendingJob($queue = null)
                {
                    return null;
                }
            };
        }
    });

    expect(queuesReport()['queues'])->toBe([['connection' => 'sqs', 'queue' => 'jobs', 'driver' => 'sqs', 'pending' => 42, 'oldest_at' => null]]);
});

it('adds the queues of Horizon and reports its status and supervisors', function () {
    config([
        'queue.default' => 'redis',
        'horizon.defaults' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default']]],
        'horizon.environments' => [
            'production' => ['supervisor-1' => ['queue' => ['default', 'emails']]],
            'testing' => ['supervisor-1' => ['queue' => ['default', 'emails']], 'supervisor-2' => ['connection' => 'redis', 'queue' => 'reports,exports']],
        ],
    ]);
    fakeRedis(['queues:emails' => [redisJob(time() - 60)]]);
    horizon(
        [(object) ['name' => 'web-1', 'status' => 'running']],
        [(object) ['name' => 'web-1:supervisor-1', 'status' => 'running', 'processes' => ['redis:default' => 3, 'redis:emails' => 1, 'redis:imports,audits' => 1]]],
    );

    $report = queuesReport();

    expect(array_map(fn (array $queue) => [$queue['queue'], $queue['pending']], $report['queues']))
        ->toBe([['default', 0], ['emails', 1], ['reports', 0], ['exports', 0], ['imports', 0], ['audits', 0]])
        ->and($report['horizon'])->toBe(['status' => 'running', 'supervisors' => [['name' => 'web-1:supervisor-1', 'status' => 'running', 'processes' => 5]]]);
});

it('tells a paused and an inactive Horizon apart', function () {
    horizon([(object) ['name' => 'web-1', 'status' => 'paused']], [(object) ['name' => 'web-1:supervisor-1', 'status' => 'paused', 'processes' => []]]);
    expect(queuesReport()['horizon'])->toBe(['status' => 'paused', 'supervisors' => [['name' => 'web-1:supervisor-1', 'status' => 'paused', 'processes' => 0]]]);

    horizon([(object) ['name' => 'web-1', 'status' => 'paused'], (object) ['name' => 'web-2', 'status' => 'running']], []);
    expect(queuesReport()['horizon']['status'])->toBe('running');

    horizon([], []);
    expect(queuesReport()['horizon'])->toBe(['status' => 'inactive', 'supervisors' => []]);
});

it('reports Horizon as unknown when its Redis is down', function () {
    app()->instance(MasterSupervisorRepository::class, new class implements MasterSupervisorRepository
    {
        public function all()
        {
            throw new RuntimeException('Connection refused');
        }
    });
    app()->instance(SupervisorRepository::class, new class implements SupervisorRepository
    {
        public function all()
        {
            throw new RuntimeException('Connection refused');
        }
    });

    expect(queuesReport())->toHaveKey('horizon', null);
});

it('stops asking a connection once it failed', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default'],
        'horizon.environments' => ['testing' => ['supervisor-1' => ['connection' => 'database', 'queue' => ['default', 'emails']]]],
    ]);
    horizon([], []);

    expect(app(Queues::class)->queues())->toBe([
        ['connection' => 'database', 'queue' => 'default', 'driver' => 'database', 'pending' => null, 'oldest_at' => null],
        ['connection' => 'database', 'queue' => 'emails', 'driver' => 'database', 'pending' => null, 'oldest_at' => null],
    ]);
});

it('matches the shared schema with queues and Horizon', function () {
    config(['queue.default' => 'redis']);
    fakeRedis(['queues:default' => [redisJob(time() - 60)]]);
    horizon([(object) ['name' => 'web-1', 'status' => 'running']], [(object) ['name' => 'web-1:supervisor-1', 'status' => 'running', 'processes' => ['redis:default' => 2]]]);
    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));

    $result = (new Validator)->validate(json_decode(json_encode((object) queuesReport())), $schema->properties->extra);

    expect($result->isValid())->toBeTrue();
});
