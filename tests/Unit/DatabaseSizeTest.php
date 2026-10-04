<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\DatabaseSize;
use StackMonitor\Agent\OperationalData;

// 2026-10-04 08:00:00 UTC.
const DATABASE_NOW = 1_791_100_800;

/** Makes a SQLite file database the default connection and returns its path. */
function databaseFile(): string
{
    $path = sys_get_temp_dir().'/sm-database-'.bin2hex(random_bytes(4)).'.sqlite';
    touch($path);
    config([
        'database.connections.file' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'file',
    ]);

    return $path;
}

/** A query callable answering each statement with the given rows, null for a failing one. */
function databaseQuery(?array $informationSchema, ?array $tableStatus): callable
{
    return fn (string $sql) => str_starts_with($sql, 'SHOW TABLE STATUS') ? $tableStatus : $informationSchema;
}

beforeEach(function () {
    $this->travelTo('2026-10-04 08:00:00');
});

it('sums data and index of every table and sends the largest, by size and name', function () {
    $rows = [];
    foreach (range(1, 12) as $i) {
        $rows[] = ['TABLE_NAME' => sprintf('t%02d', $i), 'DATA_LENGTH' => (string) ($i * 1000), 'INDEX_LENGTH' => '24', 'TABLE_ROWS' => (string) $i];
    }
    $rows[] = ['TABLE_NAME' => 'a_twin', 'DATA_LENGTH' => '12000', 'INDEX_LENGTH' => '24', 'TABLE_ROWS' => null];
    $rows[] = ['TABLE_NAME' => 'empty', 'DATA_LENGTH' => null, 'INDEX_LENGTH' => null, 'TABLE_ROWS' => '0'];

    $report = DatabaseSize::mysql(databaseQuery($rows, null), DATABASE_NOW);

    expect($report)->toMatchArray([
        'measured_at' => '2026-10-04T08:00:00+00:00',
        'engine' => 'mysql',
        'source' => 'information_schema',
        'size' => 78000 + 12 * 24 + 12024,
        'table_count' => 14,
    ])
        ->and($report['tables'])->toHaveCount(DatabaseSize::MAX_TABLES)
        ->and(array_slice($report['tables'], 0, 3))->toBe([
            ['name' => 'a_twin', 'size' => 12024, 'rows' => null],
            ['name' => 't12', 'size' => 12024, 'rows' => 12],
            ['name' => 't11', 'size' => 11024, 'rows' => 11],
        ])
        ->and(end($report['tables'])['name'])->toBe('t04');
});

it('falls back to SHOW TABLE STATUS when information_schema fails or is empty, leaving out views', function (?array $informationSchema) {
    $status = [
        ['Name' => 'wp_posts', 'Engine' => 'InnoDB', 'Data_length' => 4096, 'Index_length' => 1024, 'Rows' => 7, 'Comment' => ''],
        ['Name' => 'wp_view', 'Engine' => null, 'Data_length' => null, 'Index_length' => null, 'Rows' => null, 'Comment' => 'VIEW'],
    ];

    expect(DatabaseSize::mysql(databaseQuery($informationSchema, $status), DATABASE_NOW))->toBe([
        'measured_at' => '2026-10-04T08:00:00+00:00',
        'engine' => 'mysql',
        'source' => 'table_status',
        'size' => 5120,
        'table_count' => 1,
        'tables' => [['name' => 'wp_posts', 'size' => 5120, 'rows' => 7]],
    ]);
})->with([
    'failing' => [null],
    'empty' => [[]],
]);

it('reports nothing when both ways fail', function () {
    expect(DatabaseSize::mysql(databaseQuery(null, null), DATABASE_NOW))->toBeNull();
});

it('measures again once a day, and a failed measurement after an hour', function () {
    $measured = DatabaseSize::remember([], ['size' => 1], DATABASE_NOW);

    expect(DatabaseSize::due([], DATABASE_NOW))->toBeTrue()
        ->and(DatabaseSize::due($measured, DATABASE_NOW + DatabaseSize::MEASURE_AFTER - 1))->toBeFalse()
        ->and(DatabaseSize::due($measured, DATABASE_NOW + DatabaseSize::MEASURE_AFTER))->toBeTrue();

    $failed = DatabaseSize::remember($measured, null, DATABASE_NOW + DatabaseSize::MEASURE_AFTER);

    // The last good result goes on meanwhile.
    expect($failed['result'])->toBe(['size' => 1])
        ->and(DatabaseSize::due($failed, DATABASE_NOW + DatabaseSize::MEASURE_AFTER + DatabaseSize::RETRY_AFTER - 1))->toBeFalse()
        ->and(DatabaseSize::due($failed, DATABASE_NOW + DatabaseSize::MEASURE_AFTER + DatabaseSize::RETRY_AFTER))->toBeTrue();
});

it('measures a SQLite database by its file, with the tables from dbstat', function () {
    $path = databaseFile();
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->text('body');
        $table->index('body');
    });
    Schema::create('tags', fn (Blueprint $table) => $table->id());
    DB::statement('CREATE VIEW recent AS SELECT * FROM posts');
    DB::table('posts')->insert(array_fill(0, 200, ['body' => str_repeat('x', 500)]));

    $report = app(DatabaseSize::class)->report();
    clearstatcache();

    expect($report)->toMatchArray([
        'measured_at' => '2026-10-04T08:00:00+00:00',
        'engine' => 'sqlite',
        'source' => 'file',
        'size' => filesize($path),
        'table_count' => 2,
    ])
        ->and(array_column($report['tables'], 'name'))->toBe(['posts', 'tags'])
        ->and($report['tables'][0]['size'])->toBeGreaterThan(200 * 500)
        ->and($report['tables'][0]['rows'])->toBeNull();
});

it('keeps the measurement in the cache and measures again once it is old', function () {
    $path = databaseFile();
    Schema::create('posts', fn (Blueprint $table) => $table->id());
    $first = app(DatabaseSize::class)->report();
    Schema::create('tags', fn (Blueprint $table) => $table->id());

    $this->travel(DatabaseSize::MEASURE_AFTER - 1)->seconds();
    expect(app(DatabaseSize::class)->report())->toBe($first);

    $this->travel(1)->seconds();
    expect(app(DatabaseSize::class)->report()['table_count'])->toBe(2);
});

it('reports nothing for a database in memory or another driver', function () {
    // The test app's default connection is SQLite in memory.
    expect(app(DatabaseSize::class)->report())->toBeNull();

    cache()->forget(DatabaseSize::CACHE_KEY);
    config(['database.connections.pg' => ['driver' => 'pgsql', 'database' => 'x'], 'database.default' => 'pg']);

    expect(app(DatabaseSize::class)->report())->toBeNull();
});

it('goes into the operational data and matches the schema', function () {
    databaseFile();
    Schema::create('posts', fn (Blueprint $table) => $table->id());

    $extra = app(OperationalData::class)->report();
    $report = [
        'schema_version' => 1, 'agent_version' => '1.0.0', 'platform' => 'laravel',
        'core' => ['version' => '11.0.0', 'update_available' => null], 'php' => '8.3.0', 'packages' => [],
        'flags' => ['debug' => false, 'environment' => 'production'],
        'extra' => ['database' => $extra['database']],
    ];
    $schema = file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json');

    expect($extra['database']['engine'])->toBe('sqlite')
        ->and((new Validator)->validate(json_decode(json_encode($report)), $schema)->isValid())->toBeTrue();
});
