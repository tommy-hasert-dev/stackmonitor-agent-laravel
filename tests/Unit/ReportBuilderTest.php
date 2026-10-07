<?php

use StackMonitor\Agent\ReportBuilder;

it('builds the report from composer files', function () {
    $report = app(ReportBuilder::class)->build();

    expect($report['platform'])->toBe('laravel')
        ->and($report['core'])->toBe(['version' => app()->version(), 'update_available' => null])
        ->and($report['php'])->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION)
        ->and($report['packages'])->toBe([
            ['type' => 'composer', 'name' => 'laravel/framework', 'version' => 'v13.30.0', 'update_available' => null, 'direct' => true, 'listed' => true],
            ['type' => 'composer', 'name' => 'guzzlehttp/guzzle', 'version' => '7.9.0', 'update_available' => null, 'direct' => false, 'listed' => true],
            ['type' => 'composer', 'name' => 'acme/billing', 'version' => '2.1.0', 'update_available' => null, 'direct' => true, 'listed' => false],
        ])
        ->and($report['flags'])->toBe(['debug' => false, 'environment' => app()->environment()]);
});

it('tolerates missing composer files', function () {
    $report = (new ReportBuilder(app(), '/nonexistent'))->build();

    expect($report['packages'])->toBe([])
        ->and($report)->not->toHaveKey('npm');
});

it('reports the npm packages from the lockfile', function () {
    expect(app(ReportBuilder::class)->build()['npm'])->toBe([
        'lockfile' => 'package-lock.json',
        'truncated' => false,
        'packages' => [
            ['name' => 'axios', 'version' => '1.7.2', 'dev' => false],
            ['name' => 'vite', 'version' => '5.2.0', 'dev' => true],
        ],
    ]);
});

it('reports the errors of the configured log, without messages when switched off', function () {
    $dir = sys_get_temp_dir().'/sm-report-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/laravel.log', '['.date('Y-m-d H:i:s').'] testing.ERROR: Boom'."\n");
    config([
        'logging.default' => 'stack',
        'logging.channels.stack' => ['driver' => 'stack', 'channels' => ['single']],
        'logging.channels.single' => ['driver' => 'single', 'path' => $dir.'/laravel.log'],
        'stackmonitor-agent.log_messages' => false,
    ]);

    $log = app(ReportBuilder::class)->build()['error_log'];

    unlink($dir.'/laravel.log');
    rmdir($dir);

    expect($log['status'])->toBe('ok')
        ->and($log['errors'])->toBe(1)
        ->and($log['top'])->toBeNull();
});

it('reports the server clock and the app time zone last (#157)', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $before = microtime(true);

    $report = app(ReportBuilder::class)->build();

    expect($report['extra']->clock['timezone'])->toBe('Europe/Berlin')
        ->and($report['extra']->clock['time'])->toBeFloat()->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(microtime(true))
        ->and(array_key_last((array) $report['extra']))->toBe('clock');
});
