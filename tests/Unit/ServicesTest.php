<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Opis\JsonSchema\Validator;
use Spatie\Health\ResultStores\ResultStore;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\Services;

function services(): array
{
    return app(Services::class)->report();
}

/** @return array<string, array<string, mixed>> */
function servicesByKey(): array
{
    return collect(services()['checks'])->keyBy('key')->all();
}

function servicesDir(): string
{
    $dir = sys_get_temp_dir().'/sm-services-'.bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

/**
 * A fake SMTP server in a process of its own, answering one client with the
 * lines given; returns its port.
 *
 * @param  list<string>  $replies  sent after the greeting, one per command
 */
function fakeSmtpServer(string $greeting, array $replies): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    $script = sprintf(
        '$s = stream_socket_server("tcp://127.0.0.1:%d"); file_put_contents("php://stdout", "ready\n"); $c = stream_socket_accept($s, 5); fwrite($c, %s); foreach (%s as $r) { if (fgets($c) === false) break; fwrite($c, $r); } sleep(1);',
        $port,
        var_export($greeting, true),
        var_export($replies, true),
    );
    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);
    register_shutdown_function(fn () => proc_terminate($process));

    return $port;
}

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'queue.failed.database' => null,
        'session.driver' => 'array',
        'filesystems.default' => 'local',
        'filesystems.cloud' => null,
        'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => servicesDir()]],
        'mail.default' => 'log',
        'mail.mailers' => ['log' => ['transport' => 'log']],
    ]);
});

it('checks the default database connection', function () {
    $check = servicesByKey()['database:testing'];

    expect($check)->toMatchArray(['type' => 'database', 'name' => 'testing', 'driver' => 'sqlite', 'status' => 'ok', 'message' => null, 'depends_on' => null])
        ->and($check['duration_ms'])->toBeInt();
});

it('checks the connections the queue, cache and sessions keep their data in', function () {
    config([
        'database.connections.jobs' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'database.connections.unused' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'jobs', 'table' => 'jobs'],
    ]);

    expect(servicesByKey())->toHaveKeys(['database:testing', 'database:jobs'])
        ->not->toHaveKey('database:unused');
});

it('reports a failing connection without its credentials', function () {
    config([
        'database.connections.broken' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app', 'username' => 'appuser', 'password' => 'hunter2-secret'],
        'database.connections.missing' => ['driver' => 'sqlite', 'database' => '/nonexistent/hunter2-secret.sqlite', 'password' => 'hunter2-secret'],
        'queue.failed.database' => 'broken',
        'session.driver' => 'database',
        'session.connection' => 'missing',
    ]);

    $checks = servicesByKey();

    expect($checks['database:broken']['status'])->toBe('failed')
        ->and($checks['database:broken']['message'])->toBeString()->not->toContain('hunter2-secret')
        ->and($checks['database:missing']['status'])->toBe('failed')
        ->and($checks['database:missing']['message'])->toContain('***')->not->toContain('hunter2-secret');
});

it('writes, reads and removes a key in the default cache store', function () {
    config(['cache.default' => 'file', 'cache.stores.file' => ['driver' => 'file', 'path' => $dir = servicesDir()]]);

    expect(servicesByKey()['cache:file'])->toMatchArray(['type' => 'cache', 'driver' => 'file', 'status' => 'ok'])
        ->and(glob($dir.'/*/*/*'))->toBe([]);
});

it('leaves out a cache that keeps nothing', function () {
    expect(collect(services()['checks'])->where('type', 'cache')->all())->toBe([]);
});

it('checks only the Redis connections in use and skips the cache behind a failed one', function () {
    config([
        'database.redis' => [
            'client' => 'phpredis',
            'default' => ['host' => '127.0.0.1', 'port' => 1, 'password' => 'redis-secret-pw'],
            'unused' => ['host' => '127.0.0.1', 'port' => 1],
        ],
        'cache.default' => 'redis',
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'],
    ]);

    $checks = servicesByKey();

    expect($checks)->not->toHaveKey('redis:unused')
        ->and($checks['redis:default']['status'])->toBe('failed')
        ->and($checks['redis:default']['message'])->not->toContain('redis-secret-pw')
        ->and($checks['cache:redis'])->toMatchArray(['status' => 'skipped', 'depends_on' => 'redis:default', 'duration_ms' => null]);
});

it('checks the default queue by its size, not sync', function () {
    expect(collect(services()['checks'])->where('type', 'queue')->all())->toBe([]);

    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']]);

    expect(servicesByKey()['queue:database']['status'])->toBe('failed');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    expect(servicesByKey()['queue:database'])->toMatchArray(['driver' => 'database', 'status' => 'ok', 'depends_on' => 'database:testing']);
});

it('writes, reads and removes a file on local disks and leaves nothing behind', function () {
    $public = servicesDir();
    config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $public]]);

    $checks = servicesByKey();

    expect($checks['storage:local']['status'])->toBe('ok')
        ->and($checks['storage:public']['status'])->toBe('ok')
        ->and(scandir(config('filesystems.disks.local.root')))->toBe(['.', '..'])
        ->and(scandir($public))->toBe(['.', '..']);
});

it('reports a local disk it cannot write to', function () {
    config(['filesystems.default' => 'gone', 'filesystems.disks.gone' => ['driver' => 'local', 'root' => '/proc/stackmonitor-gone', 'throw' => true]]);

    expect(servicesByKey()['storage:gone']['status'])->toBe('failed');
});

it('leaves out disks that are not set up', function () {
    config(['filesystems.disks.s3' => ['driver' => 's3', 'bucket' => ''], 'filesystems.disks.missing' => ['driver' => 'local', 'root' => '/nonexistent/folder']]);

    expect(servicesByKey())->not->toHaveKeys(['storage:s3', 'storage:missing']);
});

it('talks to the SMTP server up to EHLO', function () {
    $port = fakeSmtpServer("220 mail.example.test ESMTP\r\n", ["250-mail.example.test\r\n250 SIZE 1000\r\n", "221 Bye\r\n"]);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'password' => 'smtp-secret']]);

    expect(servicesByKey()['mail:smtp'])->toMatchArray(['type' => 'mail', 'driver' => 'smtp', 'status' => 'ok']);
});

it('reports an SMTP server that refuses', function () {
    $port = fakeSmtpServer("554 No service\r\n", []);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port]]);

    expect(servicesByKey()['mail:smtp'])->toMatchArray(['status' => 'failed', 'message' => 'The SMTP server answered: 554 No service']);
});

it('reports an SMTP server that cannot be reached', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1]]);

    expect(servicesByKey()['mail:smtp']['status'])->toBe('failed');
});

it('only names other mailers, each of a failover', function () {
    config([
        'mail.default' => 'failover',
        'mail.mailers' => [
            'failover' => ['transport' => 'failover', 'mailers' => ['postmark', 'log']],
            'postmark' => ['transport' => 'postmark'],
            'log' => ['transport' => 'log'],
        ],
    ]);

    $checks = servicesByKey();

    expect($checks['mail:postmark'])->toMatchArray(['driver' => 'postmark', 'status' => 'configured', 'duration_ms' => null])
        ->and($checks['mail:log'])->toMatchArray(['driver' => 'log', 'status' => 'configured'])
        ->and($checks)->not->toHaveKey('mail:failover');
});

it('sends no health results without spatie/laravel-health', function () {
    expect(services()['health'])->toBeNull();
});

it('takes over the last results of spatie/laravel-health', function () {
    if (! interface_exists('Spatie\\Health\\ResultStores\\ResultStore')) {
        eval('namespace Spatie\\Health\\ResultStores; interface ResultStore { public function latestResults(); }');
    }

    $result = fn (string $name, string $status, string $message) => (object) ['name' => $name, 'label' => '', 'status' => $status, 'notificationMessage' => $message, 'shortSummary' => 'short'];
    app()->instance('Spatie\\Health\\ResultStores\\ResultStore', new class($result) implements ResultStore
    {
        public function __construct(private Closure $result) {}

        public function latestResults()
        {
            return (object) [
                'finishedAt' => new DateTimeImmutable('2026-10-11T08:00:00+00:00'),
                'storedCheckResults' => collect([
                    ($this->result)('UsedDiskSpace', 'warning', "Disk  usage\nis 85%"),
                    ($this->result)('Horizon', 'failed', ''),
                    ($this->result)('Odd', 'unknown', 'x'),
                ]),
            ];
        }
    });

    expect(services()['health'])->toBe([
        'finished_at' => '2026-10-11T08:00:00+00:00',
        'checks' => [
            ['name' => 'UsedDiskSpace', 'label' => 'UsedDiskSpace', 'status' => 'warning', 'summary' => 'Disk usage is 85%'],
            ['name' => 'Horizon', 'label' => 'Horizon', 'status' => 'failed', 'summary' => 'short'],
        ],
    ]);
});

it('goes along in the operational data', function () {
    expect(app(OperationalData::class)->report()['services']['checks'])->not->toBe([]);
});

it('matches the shared schema', function () {
    config(['cache.default' => 'redis', 'cache.stores.redis' => ['driver' => 'redis'], 'database.redis' => ['default' => ['host' => '127.0.0.1', 'port' => 1]]]);
    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode(services())), $schema->properties->extra->properties->services);

    expect($result->isValid())->toBeTrue();
});
