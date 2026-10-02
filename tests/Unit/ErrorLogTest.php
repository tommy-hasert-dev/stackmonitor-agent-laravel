<?php

use Opis\JsonSchema\Validator;
use StackMonitor\Agent\ErrorLog\ErrorLog;

const LOG_NOW = 1_790_000_000; // 2026-09-21 14:13:20 UTC

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/sm-log-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @chmod($file, 0644);
        unlink($file);
    }

    rmdir($this->dir);
});

function logLine(int $secondsAgo, string $level, string $message): string
{
    return '['.gmdate('Y-m-d H:i:s', LOG_NOW - $secondsAgo).'] production.'.$level.': '.$message."\n";
}

function errorLog(array $channels, string $default = 'stack', bool $messages = true): ErrorLog
{
    return new ErrorLog($channels, $default, 'UTC', $messages);
}

it('counts errors of the last 24 hours in a single file and names the most frequent', function () {
    file_put_contents($this->dir.'/laravel.log',
        logLine(90_000, 'ERROR', 'Too old')
        .logLine(3_600, 'ERROR', 'Mail to a@example.com failed {"exception":"[object] (Exception(code: 0): x at /var/www/app.php:3)"}')
        ."[stacktrace]\n#0 /var/www/vendor/x.php(12): run()\n"
        .logLine(1_800, 'WARNING', 'Only a warning')
        .logLine(1_200, 'CRITICAL', 'Mail to b@example.com failed')
        .logLine(600, 'ERROR', 'Undefined array key 7 {"userId":1}')
        .logLine(60, 'INFO', 'Done'),
    );

    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    expect($report)->toBe([
        'status' => 'ok',
        'reason' => null,
        'source' => 'laravel.log',
        'errors' => 3,
        'warnings' => null,
        'truncated' => false,
        'top' => [
            ['message' => 'Mail to <email> failed', 'level' => 'error', 'count' => 2],
            ['message' => 'Undefined array key N', 'level' => 'error', 'count' => 1],
        ],
    ]);
});

it('reads today\'s and yesterday\'s file of a daily channel', function () {
    $today = gmdate('Y-m-d', LOG_NOW);
    $yesterday = gmdate('Y-m-d', LOG_NOW - 86_400);
    file_put_contents($this->dir."/app-{$yesterday}.log", logLine(90_000, 'ERROR', 'Too old').logLine(80_000, 'ERROR', 'Yesterday'));
    file_put_contents($this->dir."/app-{$today}.log", logLine(100, 'ERROR', 'Today'));

    $report = errorLog(['daily' => ['driver' => 'daily', 'path' => $this->dir.'/app.log']], 'daily')->report(LOG_NOW);

    expect($report['errors'])->toBe(2)
        ->and($report['source'])->toBe('app-*.log')
        ->and(array_column($report['top'], 'message'))->toBe(['Today', 'Yesterday']);
});

it('finds the file channel inside a stack, also a nested one', function () {
    file_put_contents($this->dir.'/laravel.log', logLine(100, 'ERROR', 'Boom'));
    $channels = [
        'stack' => ['driver' => 'stack', 'channels' => ['inner']],
        'inner' => ['driver' => 'stack', 'channels' => 'stderr,single'],
        'stderr' => ['driver' => 'monolog'],
        'single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log'],
    ];

    expect(errorLog($channels)->report(LOG_NOW)['errors'])->toBe(1);
});

it('reports a log without a file channel as not evaluable', function (array $channels) {
    expect(errorLog($channels)->report(LOG_NOW))->toBe([
        'status' => 'unavailable',
        'reason' => 'no_file',
        'source' => null,
        'errors' => null,
        'warnings' => null,
        'truncated' => false,
        'top' => null,
    ]);
})->with([
    'stderr' => [['stack' => ['driver' => 'stack', 'channels' => ['stderr']], 'stderr' => ['driver' => 'monolog']]],
    'unknown channel' => [['stack' => ['driver' => 'stack', 'channels' => ['sentry']]]],
    'stack in itself' => [['stack' => ['driver' => 'stack', 'channels' => ['stack']]]],
    'no channels' => [[]],
]);

it('counts nothing while the log file doesn\'t exist yet', function () {
    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    expect($report['status'])->toBe('ok')
        ->and($report['errors'])->toBe(0)
        ->and($report['top'])->toBe([]);
});

it('reports a log it can\'t read', function (string $path) {
    $report = errorLog(['single' => ['driver' => 'single', 'path' => $path]], 'single')->report(LOG_NOW);

    expect($report['status'])->toBe('unavailable')
        ->and($report['reason'])->toBe('not_readable');
})->with([
    'directory missing' => ['/nonexistent/logs/laravel.log'],
]);

it('reports an unreadable file', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root reads every file');
    }

    file_put_contents($this->dir.'/laravel.log', logLine(100, 'ERROR', 'Boom'));
    chmod($this->dir.'/laravel.log', 0000);

    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    expect($report['reason'])->toBe('not_readable');
});

it('reads at most the last 5 MB and says so', function () {
    $filler = str_repeat(logLine(7_200, 'INFO', str_repeat('x', 200)), (int) ceil(ErrorLog::READ_LIMIT / 240));
    file_put_contents($this->dir.'/laravel.log', logLine(3_600 * 3, 'ERROR', 'Hidden').$filler.logLine(10, 'ERROR', 'Seen'));

    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    expect($report['errors'])->toBe(1)
        ->and($report['truncated'])->toBeTrue();
});

it('counts a line longer than a read block once', function () {
    file_put_contents($this->dir.'/laravel.log',
        logLine(200, 'ERROR', 'Before')
        .logLine(100, 'ERROR', 'Long '.str_repeat('x', ErrorLog::BLOCK * 2))
        .logLine(50, 'ERROR', 'After'),
    );

    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    expect($report['errors'])->toBe(3)
        ->and($report['truncated'])->toBeFalse();
});

it('reads a big log in blocks, not all at once', function () {
    file_put_contents($this->dir.'/laravel.log', str_repeat(logLine(600, 'ERROR', str_repeat('y', 200)), 20_000));
    $log = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single');
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $report = $log->report(LOG_NOW);

    expect($report['errors'])->toBe(20_000)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(ErrorLog::READ_LIMIT / 4);
});

it('leaves the messages out when they are switched off', function () {
    file_put_contents($this->dir.'/laravel.log', logLine(100, 'ERROR', 'Boom'));

    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single', messages: false)->report(LOG_NOW);

    expect($report['errors'])->toBe(1)
        ->and($report['top'])->toBeNull();
});

it('reads timestamps in the app timezone or with their own offset', function () {
    file_put_contents($this->dir.'/laravel.log',
        // 25 hours ago in Berlin time (UTC+2 in September) would be 23 hours ago read as UTC.
        '['.gmdate('Y-m-d H:i:s', LOG_NOW - 90_000 + 7_200).'] production.ERROR: Old in Berlin'."\n"
        .'['.gmdate('Y-m-d\TH:i:s.u', LOG_NOW - 60).'+00:00] production.ERROR: With offset'."\n",
    );

    $report = (new ErrorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single', 'Europe/Berlin', true))->report(LOG_NOW);

    expect(array_column($report['top'], 'message'))->toBe(['With offset']);
});

it('matches the shared schema', function () {
    file_put_contents($this->dir.'/laravel.log', logLine(100, 'ERROR', 'Boom'));
    $report = errorLog(['single' => ['driver' => 'single', 'path' => $this->dir.'/laravel.log']], 'single')->report(LOG_NOW);

    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode($report)), $schema->properties->error_log);

    expect($result->isValid())->toBeTrue();
});
