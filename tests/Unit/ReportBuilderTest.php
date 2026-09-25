<?php

use StackMonitor\Agent\ReportBuilder;

it('builds the report from composer files', function () {
    $report = app(ReportBuilder::class)->build();

    expect($report['platform'])->toBe('laravel')
        ->and($report['core'])->toBe(['version' => app()->version(), 'update_available' => null])
        ->and($report['php'])->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION)
        ->and($report['packages'])->toBe([
            ['type' => 'composer', 'name' => 'laravel/framework', 'version' => 'v13.30.0', 'update_available' => null, 'direct' => true],
            ['type' => 'composer', 'name' => 'guzzlehttp/guzzle', 'version' => '7.9.0', 'update_available' => null, 'direct' => false],
        ])
        ->and($report['flags'])->toBe(['debug' => false, 'environment' => app()->environment()]);
});

it('tolerates missing composer files', function () {
    $report = (new ReportBuilder(app(), '/nonexistent'))->build();

    expect($report['packages'])->toBe([]);
});
