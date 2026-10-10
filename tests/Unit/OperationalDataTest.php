<?php

use Illuminate\Auth\Events\Attempting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\SchedulerHeartbeat;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function operationalData(): array
{
    return app(OperationalData::class)->report();
}

function tempDir(): string
{
    $dir = sys_get_temp_dir().'/sm-ops-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
    app()->instance('queue.failer', new NullFailedJobProvider);
    app()->useDatabasePath(tempDir());
});

it('counts the failed jobs', function () {
    $failer = new FileFailedJobProvider(tempDir().'/failed-jobs.json');
    $failer->log('database', 'default', '{"uuid":"a"}', new RuntimeException('Boom'));
    $failer->log('database', 'default', '{"uuid":"b"}', new RuntimeException('Boom'));
    app()->instance('queue.failer', $failer);

    expect(operationalData()['failed_jobs'])->toBe(2);
});

it('leaves out failed jobs when they are not stored', function () {
    expect(operationalData())->not->toHaveKey('failed_jobs');
});

it('counts the jobs waiting in the default queue', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']]);
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    foreach ([time() - 60, time() - 30, time() + 3600] as $availableAt) {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $availableAt, 'created_at' => time()]);
    }

    // The delayed job isn't waiting yet.
    expect(operationalData()['queue_pending'])->toBe(2);
});

it('reports the queue as unknown where it cannot tell waiting from delayed jobs', function () {
    // Laravel 10 and early 11 have no pendingSize(); size() counts delayed and reserved jobs too.
    config(['queue.default' => 'legacy', 'queue.connections.legacy' => ['driver' => 'legacy']]);
    app('queue')->addConnector('legacy', fn () => new class implements ConnectorInterface
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

                public function size($queue = null)
                {
                    return 150;
                }
            };
        }
    });

    expect(operationalData()['queue_pending'])->toBeNull();
});

it('leaves out the queue for the sync driver', function () {
    expect(operationalData())->not->toHaveKey('queue_pending');
});

it('reports an unreadable queue as unknown', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default']]);

    expect(operationalData()['queue_pending'])->toBeNull();
});

it('reports no scheduler run before one was seen', function () {
    expect(operationalData()['scheduler'])->toBeNull();
});

it('records the scheduler runs with the number of scheduled tasks', function () {
    app(Schedule::class)->command('inspire')->hourly();
    app(Schedule::class)->call(fn () => null)->daily();

    event(new CommandStarting('inspire', new ArrayInput([]), new NullOutput));
    expect(operationalData()['scheduler'])->toBeNull();

    $this->travelTo('2026-10-02 10:00:00');
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

    expect(operationalData()['scheduler'])->toBe(['last_run_at' => '2026-10-02T10:00:00+00:00', 'tasks' => 2]);
});

it('does not count its own heartbeat as a task of the app', function () {
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

    expect(operationalData()['scheduler']['tasks'])->toBe(0);
});

it('never lets a broken cache break the scheduler', function () {
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'redis', 'connection' => 'missing']]);

    app(SchedulerHeartbeat::class)->record();

    expect(true)->toBeTrue();
});

it('reports the free disk space of the app', function () {
    $disk = operationalData()['disk'];

    expect($disk['free'])->toBeInt()->toBeGreaterThan(0)
        ->and($disk['total'])->toBeGreaterThanOrEqual($disk['free']);
});

it('reports unknown disk space where it cannot be read', function () {
    $report = (new OperationalData(app(), '/nonexistent/'.bin2hex(random_bytes(4))))->report();

    expect($report['disk'])->toBeNull();
});

it('counts the migrations that have not run', function () {
    mkdir(database_path('migrations'));
    foreach (['2026_01_01_000000_create_a', '2026_01_02_000000_create_b', '2026_01_03_000000_create_c'] as $name) {
        file_put_contents(database_path("migrations/{$name}.php"), '<?php return new class extends Illuminate\Database\Migrations\Migration {};');
    }
    $repository = app('migrator')->getRepository();
    $repository->createRepository();
    $repository->log('2026_01_01_000000_create_a', 1);

    expect(operationalData()['migrations_pending'])->toBe(2);
});

it('reports migrations as unknown without a migrations table', function () {
    expect(operationalData()['migrations_pending'])->toBeNull();
});

it('reports whether config and routes are cached', function () {
    expect(operationalData())->toMatchArray(['config_cached' => false, 'routes_cached' => false]);
});

it('reports the failed logins once the app uses Laravel auth', function () {
    expect(operationalData()['failed_logins'])->toBeNull();

    $this->travelTo('2026-10-04 08:00:00');
    event(new Attempting('web', ['email' => 'jane@example.com'], false));

    expect(operationalData()['failed_logins'])->toBe(['since' => '2026-10-04T08:00:00+00:00', 'hours' => []]);
});

it('matches the shared schema', function () {
    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode((object) operationalData())), $schema->properties->extra);

    expect($result->isValid())->toBeTrue();
});

it('reports the cache store and that no response cache is installed', function () {
    config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache']]);

    expect(operationalData()['cache'])->toBe(['store' => 'redis', 'driver' => 'redis', 'response_cache' => null]);
});

it('knows an unknown cache store has no driver', function () {
    config(['cache.default' => 'missing']);

    expect(operationalData()['cache'])->toBe(['store' => 'missing', 'driver' => null, 'response_cache' => null]);
});
