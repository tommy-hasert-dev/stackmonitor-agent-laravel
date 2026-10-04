<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Throwable;

/**
 * The size of the app's database (#149), sent in `extra.database`: hosting
 * plans limit it, and a table that grows without end (logs, sessions, jobs)
 * is best seen before the limit is reached. MySQL and MariaDB are read from
 * `information_schema`, with `SHOW TABLE STATUS` where that is closed off;
 * SQLite by the size of its file. Besides the whole size go the number of
 * tables and the MAX_TABLES largest, never their content.
 *
 * Sizes change slowly and reading them can take a moment on a large
 * database, so the agent measures once MEASURE_AFTER has passed and sends the
 * result kept in the app's cache meanwhile. A failed measurement is tried
 * again after RETRY_AFTER; until then the last good one goes on.
 */
final class DatabaseSize
{
    public const CACHE_KEY = 'stackmonitor-agent:database';

    /**
     * Seconds after which the database is measured again. A day minus an
     * hour: with hourly reports a full day would drift later every day and
     * now and then skip a day in the dashboard's daily history.
     */
    public const MEASURE_AFTER = 82800;

    /** Seconds after which a failed measurement is tried again. */
    public const RETRY_AFTER = 3600;

    /** The largest tables sent, as the report schema takes them. */
    public const MAX_TABLES = 10;

    public function __construct(private readonly Application $app) {}

    /**
     * Measures when due and returns the last good measurement, null before
     * the first one.
     *
     * @return array{measured_at: string, engine: string, source: string, size: int, table_count: int, tables: list<array{name: string, size: int, rows: int|null}>}|null
     */
    public function report(): ?array
    {
        $state = $this->cache()->get(self::CACHE_KEY);
        $state = is_array($state) ? $state : [];
        $now = now()->getTimestamp();

        if (self::due($state, $now)) {
            $state = self::remember($state, $this->measure($now), $now);
            $this->cache()->forever(self::CACHE_KEY, $state);
        }

        return is_array($state['result'] ?? null) ? $state['result'] : null;
    }

    /**
     * Whether to measure again: before the first measurement, once the last
     * one is MEASURE_AFTER old, or RETRY_AFTER after a failed one.
     *
     * @param  array<string, mixed>  $state  as cached
     */
    public static function due(array $state, int $now): bool
    {
        if (! isset($state['attempted_at'])) {
            return true;
        }

        $after = ($state['failed'] ?? true) ? self::RETRY_AFTER : self::MEASURE_AFTER;

        return $now - (int) $state['attempted_at'] >= $after;
    }

    /**
     * The state to cache after a measurement; a failed one (null) keeps the
     * last good result.
     *
     * @param  array<string, mixed>  $state  as cached
     * @param  array<string, mixed>|null  $measured
     * @return array{attempted_at: int, failed: bool, result: array<string, mixed>|null}
     */
    public static function remember(array $state, ?array $measured, int $now): array
    {
        return [
            'attempted_at' => $now,
            'failed' => $measured === null,
            'result' => $measured ?? (is_array($state['result'] ?? null) ? $state['result'] : null),
        ];
    }

    /**
     * MySQL or MariaDB: the tables from `information_schema`, or from `SHOW
     * TABLE STATUS` when that fails or shows none (some hosts close it off);
     * null when both fail.
     *
     * @param  callable(string): (list<array<string, mixed>>|null)  $query  null for a failing query
     * @return array{measured_at: string, engine: string, source: string, size: int, table_count: int, tables: list<array{name: string, size: int, rows: int|null}>}|null
     */
    public static function mysql(callable $query, int $now): ?array
    {
        $rows = $query("SELECT TABLE_NAME, DATA_LENGTH, INDEX_LENGTH, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");

        if (is_array($rows) && $rows !== []) {
            return self::forReport('mysql', 'information_schema', $now, self::tables($rows, 'TABLE_NAME', 'DATA_LENGTH', 'INDEX_LENGTH', 'TABLE_ROWS'));
        }

        $rows = $query('SHOW TABLE STATUS');

        if (! is_array($rows)) {
            return null;
        }

        // Views come along with neither engine nor sizes.
        $rows = array_filter($rows, function (array $row) {
            $row = array_change_key_case($row);

            return ($row['engine'] ?? null) !== null && strtoupper((string) ($row['comment'] ?? '')) !== 'VIEW';
        });

        return self::forReport('mysql', 'table_status', $now, self::tables($rows, 'Name', 'Data_length', 'Index_length', 'Rows'));
    }

    /**
     * The report's `database`: the whole size and the number of tables, by
     * default summed and counted over the tables, and the MAX_TABLES largest,
     * by size and name.
     *
     * @param  list<array{name: string, size: int, rows: int|null}>  $tables
     * @return array{measured_at: string, engine: string, source: string, size: int, table_count: int, tables: list<array{name: string, size: int, rows: int|null}>}
     */
    public static function forReport(string $engine, string $source, int $now, array $tables, ?int $size = null, ?int $tableCount = null): array
    {
        usort($tables, fn (array $a, array $b) => [$b['size'], $a['name']] <=> [$a['size'], $b['name']]);

        return [
            'measured_at' => date(DATE_ATOM, $now),
            'engine' => $engine,
            'source' => $source,
            'size' => $size ?? array_sum(array_column($tables, 'size')),
            'table_count' => $tableCount ?? count($tables),
            'tables' => array_slice($tables, 0, self::MAX_TABLES),
        ];
    }

    /**
     * The rows of either query as tables, whatever case the server gives the
     * column names in; a size the server doesn't know counts as 0.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<array{name: string, size: int, rows: int|null}>
     */
    private static function tables(iterable $rows, string $name, string $data, string $index, string $count): array
    {
        $tables = [];

        foreach ($rows as $row) {
            $row = array_change_key_case($row);
            $table = (string) ($row[strtolower($name)] ?? '');

            if ($table === '') {
                continue;
            }

            $rowCount = $row[strtolower($count)] ?? null;
            $tables[] = [
                'name' => mb_substr($table, 0, 200),
                'size' => max(0, (int) ($row[strtolower($data)] ?? 0) + (int) ($row[strtolower($index)] ?? 0)),
                'rows' => $rowCount === null ? null : max(0, (int) $rowCount),
            ];
        }

        return $tables;
    }

    /**
     * Measures the default connection; null for a driver without a size to
     * read (PostgreSQL, SQL Server), a database in memory or a failure.
     *
     * @return array<string, mixed>|null
     */
    private function measure(int $now): ?array
    {
        try {
            $connection = $this->app['db']->connection();

            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => self::mysql(function (string $sql) use ($connection) {
                    try {
                        return array_map(fn ($row) => (array) $row, $connection->select($sql));
                    } catch (Throwable) {
                        return null;
                    }
                }, $now),
                'sqlite' => $this->sqlite($connection, $now),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * SQLite: the size of the file, and the tables with their indexes from
     * the `dbstat` table where SQLite is built with it. SQLite estimates no
     * rows.
     *
     * @return array<string, mixed>|null
     */
    private function sqlite(Connection $connection, int $now): ?array
    {
        $path = (string) $connection->getConfig('database');
        $size = $path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') ? false : @filesize($path);

        if (! is_int($size)) {
            return null;
        }

        $names = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'";
        $tableCount = count($connection->select($names));

        try {
            $rows = $connection->select("SELECT m.tbl_name AS name, SUM(d.pgsize) AS size FROM dbstat d JOIN sqlite_master m ON m.name = d.name WHERE m.tbl_name IN ({$names}) GROUP BY m.tbl_name");
        } catch (Throwable) {
            $rows = [];
        }

        $tables = array_map(fn ($row) => ['name' => mb_substr((string) $row->name, 0, 200), 'size' => (int) $row->size, 'rows' => null], $rows);

        return self::forReport('sqlite', 'file', $now, $tables, $size, $tableCount);
    }

    private function cache(): Repository
    {
        return $this->app['cache']->store();
    }
}
