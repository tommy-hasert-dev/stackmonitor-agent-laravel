<?php

namespace StackMonitor\Agent;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use PDO;
use Throwable;

/**
 * The services the app depends on (#156), sent in `extra.services`: when
 * Redis, the mail server or a bucket fails, the homepage often still answers
 * 200 while sessions, jobs, uploads and mails fail. What to check comes from
 * the app's config, each service is tried once per report with a timeout of
 * TIMEOUT seconds: the database connections in use with `select 1`, the
 * Redis connections in use with PING, the default cache store by writing,
 * reading and removing a key, the default queue by its size, the disks in
 * use (local ones by writing, reading and removing a file, remote ones only
 * by reading, which costs nothing and leaves nothing behind) and the SMTP
 * server up to EHLO, without sending a mail; other mailers are only named.
 * A service whose connection already failed is skipped. The last results of
 * spatie/laravel-health come along when the app has it. Messages leave out
 * the credentials from the config.
 *
 * @phpstan-type Check array{key: string, type: string, name: string, driver: string|null, status: string, duration_ms: int|null, message: string|null, depends_on: string|null}
 */
final class Services
{
    /** Seconds each service may take to answer. */
    public const TIMEOUT = 2;

    /** The most checks and health results sent, as the report schema takes them. */
    public const MAX_CHECKS = 30;

    /** The longest message sent, as the report schema takes it. */
    public const MAX_MESSAGE = 300;

    /** The start of every key and file the agent writes. */
    public const PREFIX = 'stackmonitor-agent-probe-';

    /** @var array<string, string> the status of each check by its key, for the ones depending on it */
    private array $status = [];

    private readonly Secrets $secrets;

    public function __construct(private readonly Application $app)
    {
        $this->secrets = new Secrets;
    }

    /**
     * @return array{checks: list<Check>, health: array{finished_at: string|null, checks: list<array{name: string, label: string, status: string, summary: string|null}>}|null}
     */
    public function report(): array
    {
        $checks = [];

        foreach ($this->databaseConnections() as $name) {
            $checks[] = $this->database($name);
        }

        foreach ($this->redisConnections() as $name) {
            $checks[] = $this->redis($name);
        }

        $checks[] = $this->cache();
        $checks[] = $this->queue();

        foreach ($this->disks() as $name) {
            $checks[] = $this->disk($name);
        }

        foreach ($this->mailers() as $name) {
            $checks[] = $this->mailer($name);
        }

        return [
            'checks' => array_slice(array_values(array_filter($checks)), 0, self::MAX_CHECKS),
            'health' => $this->health(),
        ];
    }

    /**
     * The default connection and the ones the queue, the failed jobs, the
     * cache and the sessions keep their data in.
     *
     * @return list<string>
     */
    private function databaseConnections(): array
    {
        $default = config('database.default');
        $names = [$default];

        foreach (Value::array(config('queue.connections')) as $connection) {
            if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
                $names[] = $connection['connection'] ?? $default;
            }
        }

        foreach (Value::array(config('cache.stores')) as $store) {
            if (is_array($store) && ($store['driver'] ?? null) === 'database') {
                $names[] = $store['connection'] ?? $default;
            }
        }

        $names[] = config('queue.failed.database');
        $names[] = config('session.driver') === 'database' ? (config('session.connection') ?? $default) : null;

        return $this->existing($names, Value::array(config('database.connections')));
    }

    /**
     * The Redis connections the default cache store, the default queue and
     * the sessions use; the ones in the config by default but unused stay
     * out, as most apps have no Redis.
     *
     * @return list<string>
     */
    private function redisConnections(): array
    {
        $names = [
            ($this->cacheStore()['driver'] ?? null) === 'redis' ? ($this->cacheStore()['connection'] ?? 'default') : null,
            ($this->queueConnection()['driver'] ?? null) === 'redis' ? ($this->queueConnection()['connection'] ?? 'default') : null,
            config('session.driver') === 'redis' ? (config('session.connection') ?? 'default') : null,
        ];
        $connections = array_diff_key(Value::array(config('database.redis')), array_flip(['client', 'options', 'clusters']));

        return $this->existing($names, $connections);
    }

    /**
     * The default disk, the cloud disk and the others set up: local ones
     * whose folder exists, S3 with a bucket, FTP and SFTP with a host.
     *
     * @return list<string>
     */
    private function disks(): array
    {
        $disks = Value::array(config('filesystems.disks'));
        $names = [config('filesystems.default'), config('filesystems.cloud')];

        foreach ($disks as $name => $disk) {
            if (! is_array($disk)) {
                continue;
            }

            $names[] = match ($disk['driver'] ?? null) {
                'local' => is_string($disk['root'] ?? null) && is_dir($disk['root']) ? $name : null,
                's3' => filled($disk['bucket'] ?? null) ? $name : null,
                'ftp', 'sftp' => filled($disk['host'] ?? null) ? $name : null,
                default => null,
            };
        }

        return $this->existing($names, $disks);
    }

    /**
     * The default mailer, or the ones it hands over to (failover, round robin).
     *
     * @return list<string>
     */
    private function mailers(): array
    {
        $mailers = Value::array(config('mail.mailers'));
        $default = config('mail.default');
        $config = is_string($default) ? ($mailers[$default] ?? null) : null;

        if (is_array($config) && in_array($config['transport'] ?? null, ['failover', 'roundrobin'], true)) {
            return $this->existing((array) ($config['mailers'] ?? []), $mailers);
        }

        return $this->existing([$default], $mailers);
    }

    /**
     * @return Check
     */
    private function database(string $name): array
    {
        $config = Value::array(config("database.connections.{$name}"));
        $driver = $this->string($config['driver'] ?? null);

        return $this->attempt('database', $name, $driver, $config, function () use ($config, $name, $driver) {
            $config = match ($driver) {
                'mysql', 'mariadb' => [...$config, 'options' => array_replace((array) ($config['options'] ?? []), [PDO::ATTR_TIMEOUT => self::TIMEOUT])],
                'pgsql' => [...$config, 'connect_timeout' => self::TIMEOUT],
                'sqlsrv' => [...$config, 'login_timeout' => self::TIMEOUT],
                default => $config,
            };
            // A connection of its own, so the timeout doesn't change the app's.
            $connection = $this->app->make('db.factory')->make($config, $name);
            $connection->select('select 1');
            $connection->disconnect();
        });
    }

    /**
     * @return Check
     */
    private function redis(string $name): array
    {
        $redis = Value::array(config('database.redis'));
        $config = Value::array($redis[$name] ?? null);

        return $this->attempt('redis', $name, $this->string($redis['client'] ?? 'phpredis'), $config, function () use ($redis, $name, $config) {
            $manager = new RedisManager($this->app, Value::string($redis['client'] ?? null, 'phpredis'), [
                ...$redis,
                $name => [...$config, 'timeout' => self::TIMEOUT, 'read_timeout' => self::TIMEOUT, 'read_write_timeout' => self::TIMEOUT],
            ]);
            $manager->connection($name)->command('ping');
            // @phpstan-ignore method.notFound (PhpRedis has it, Predis passes it on to its client)
            $manager->connection($name)->disconnect();
        });
    }

    /**
     * The default store; one that keeps nothing (array, null) has nothing to check.
     *
     * @return Check|null
     */
    private function cache(): ?array
    {
        $store = config('cache.default');
        $config = $this->cacheStore();
        $driver = $this->string($config['driver'] ?? null);

        if (! is_string($store) || $driver === null || in_array($driver, ['array', 'null'], true)) {
            return null;
        }

        return $this->attempt('cache', $store, $driver, $config, function () use ($store) {
            $cache = $this->app->make('cache')->store($store);
            $key = self::PREFIX.bin2hex(random_bytes(8));
            $value = bin2hex(random_bytes(8));

            try {
                $cache->put($key, $value, 60);

                if ($cache->get($key) !== $value) {
                    throw new \RuntimeException('The value written could not be read back.');
                }
            } finally {
                $cache->forget($key);
            }
        }, $this->dependency($driver, $config['connection'] ?? null));
    }

    /**
     * The default queue by its size; sync and null have no connection.
     *
     * @return Check|null
     */
    private function queue(): ?array
    {
        $name = config('queue.default');
        $config = $this->queueConnection();
        $driver = $this->string($config['driver'] ?? null);

        if (! is_string($name) || $driver === null || in_array($driver, ['sync', 'null'], true)) {
            return null;
        }

        return $this->attempt('queue', $name, $driver, $config, fn () => $this->app->make('queue')->connection($name)->size(), $this->dependency($driver, $config['connection'] ?? null));
    }

    /**
     * @return Check
     */
    private function disk(string $name): array
    {
        $config = Value::array(config("filesystems.disks.{$name}"));
        $driver = $this->string($config['driver'] ?? null);

        return $this->attempt('storage', $name, $driver, $config, function () use ($name, $config, $driver) {
            if ($driver === 'local' && ! ($config['read-only'] ?? false)) {
                $this->writeAndRead($this->app->make('filesystem')->disk($name));

                return;
            }

            $config = match ($driver) {
                's3' => [...$config, 'http' => array_replace((array) ($config['http'] ?? []), ['connect_timeout' => self::TIMEOUT, 'timeout' => self::TIMEOUT])],
                'ftp', 'sftp' => [...$config, 'timeout' => self::TIMEOUT],
                default => $config,
            };
            $disk = $this->app->make('filesystem')->build($config);

            // S3 takes a missing file for wrong credentials too; listing
            // one key tells them apart.
            $client = $driver === 's3' && method_exists($disk, 'getClient') ? $disk->getClient() : null;

            // The AWS SDK serves its operations through __call, which method_exists() doesn't see.
            if (is_object($client) && is_callable([$client, 'listObjectsV2'])) {
                $client->listObjectsV2(['Bucket' => $config['bucket'], 'MaxKeys' => 1, 'Prefix' => Value::string($config['root'] ?? null)]);

                return;
            }

            $disk->exists(self::PREFIX.'check');
        });
    }

    private function writeAndRead(Filesystem $disk): void
    {
        $path = self::PREFIX.bin2hex(random_bytes(8)).'.txt';
        $content = bin2hex(random_bytes(8));

        try {
            if (! $disk->put($path, $content) || $disk->get($path) !== $content) {
                throw new \RuntimeException('The file written could not be read back.');
            }
        } finally {
            $disk->delete($path);
        }
    }

    /**
     * SMTP up to EHLO, without logging in or sending; the other transports
     * (Mailgun, Postmark, SES, sendmail, log …) are only named.
     *
     * @return Check
     */
    private function mailer(string $name): array
    {
        $config = Value::array(config("mail.mailers.{$name}"));
        $transport = $this->string($config['transport'] ?? null);

        if ($transport !== 'smtp') {
            return $this->result('mail', $name, $transport, 'configured');
        }

        return $this->attempt('mail', $name, $transport, $config, function () use ($config) {
            $port = Value::int($config['port'] ?? null, 587);
            $tls = ($config['scheme'] ?? null) === 'smtps' || ($config['encryption'] ?? null) === 'ssl' || $port === 465;
            SmtpProbe::ehlo(Value::string($config['host'] ?? null), $port, $tls, self::TIMEOUT);
        });
    }

    /**
     * The last run of spatie/laravel-health, when the app has it; its
     * results come as they are, its own schedule decides how fresh they are.
     *
     * @return array{finished_at: string|null, checks: list<array{name: string, label: string, status: string, summary: string|null}>}|null
     */
    private function health(): ?array
    {
        $store = 'Spatie\\Health\\ResultStores\\ResultStore';

        if (! interface_exists($store)) {
            return null;
        }

        try {
            $repository = $this->app->make($store);
            $latest = is_object($repository) && method_exists($repository, 'latestResults') ? $repository->latestResults() : false;
        } catch (Throwable) {
            return null;
        }

        if (! is_object($latest)) {
            return $latest === null ? ['finished_at' => null, 'checks' => []] : null;
        }

        $results = $latest->storedCheckResults ?? [];
        $finished = $latest->finishedAt ?? null;
        $checks = [];

        foreach (is_iterable($results) ? $results : [] as $result) {
            $status = is_object($result) ? $this->string($result->status ?? null) : null;

            if (! is_object($result) || ! in_array($status, ['ok', 'warning', 'failed', 'crashed', 'skipped'], true)) {
                continue;
            }

            $name = Value::string($result->name ?? null);
            $label = Value::string($result->label ?? null);
            $checks[] = [
                'name' => mb_substr($name, 0, 100),
                'label' => mb_substr($label !== '' ? $label : $name, 0, 100),
                'status' => $status,
                'summary' => $this->clean($this->string($result->notificationMessage ?? null) ?? $this->string($result->shortSummary ?? null)),
            ];
        }

        return [
            'finished_at' => $finished instanceof DateTimeInterface ? $finished->format(DATE_ATOM) : null,
            'checks' => array_slice($checks, 0, self::MAX_CHECKS),
        ];
    }

    /**
     * The check a cache or queue on the database or Redis depends on.
     */
    private function dependency(?string $driver, mixed $connection): ?string
    {
        return match ($driver) {
            'database' => 'database:'.Value::string($connection ?? config('database.default')),
            'redis' => 'redis:'.Value::string($connection, 'default'),
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  callable(): mixed  $check
     * @return Check
     */
    private function attempt(string $type, string $name, ?string $driver, array $config, callable $check, ?string $dependsOn = null): array
    {
        if ($dependsOn !== null && ($this->status[$dependsOn] ?? null) === 'failed') {
            return $this->result($type, $name, $driver, 'skipped', dependsOn: $dependsOn);
        }

        $this->secrets->collect($config);
        $started = hrtime(true);

        try {
            $check();

            return $this->result($type, $name, $driver, 'ok', $started, dependsOn: $dependsOn);
        } catch (Throwable $e) {
            return $this->result($type, $name, $driver, 'failed', $started, $this->clean($e->getMessage()) ?? $e::class, $dependsOn);
        }
    }

    /**
     * @return Check
     */
    private function result(string $type, string $name, ?string $driver, string $status, ?int $started = null, ?string $message = null, ?string $dependsOn = null): array
    {
        $key = "{$type}:{$name}";
        $this->status[$key] = $status;

        return [
            'key' => mb_substr($key, 0, 150),
            'type' => $type,
            'name' => mb_substr($name, 0, 100),
            'driver' => $driver === null ? null : mb_substr($driver, 0, 50),
            'status' => $status,
            'duration_ms' => $started === null ? null : (int) round((hrtime(true) - $started) / 1e6),
            'message' => $message,
            'depends_on' => $dependsOn,
        ];
    }

    /**
     * One line, without the credentials of the services checked and without
     * user and password in a URL.
     */
    private function clean(?string $message): ?string
    {
        return $this->secrets->clean($message, self::MAX_MESSAGE);
    }

    /**
     * @param  array<mixed>  $names
     * @param  array<array-key, mixed>  $configured
     * @return list<string>
     */
    private function existing(array $names, array $configured): array
    {
        return array_values(array_unique(array_filter(
            $names,
            fn (mixed $name) => is_string($name) && $name !== '' && is_array($configured[$name] ?? null),
        )));
    }

    /** @return array<string, mixed> */
    private function cacheStore(): array
    {
        $store = config('cache.default');

        return is_string($store) ? Value::map(config("cache.stores.{$store}")) : [];
    }

    /** @return array<string, mixed> */
    private function queueConnection(): array
    {
        $name = config('queue.default');

        return is_string($name) ? Value::map(config("queue.connections.{$name}")) : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
