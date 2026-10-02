<?php

use Opis\JsonSchema\Validator;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\PhpConfig;

function phpConfig(array $ini = [], ?string $displayErrors = '0', bool $opcacheLoaded = true, mixed $opcache = false, array $extensions = ['Core', 'intl']): array
{
    $ini = [
        'memory_limit' => '256M', 'max_execution_time' => '30', 'upload_max_filesize' => '64M', 'post_max_size' => '64M',
        'error_reporting' => '32767', 'date.timezone' => 'Europe/Berlin', 'opcache.enable' => '1', ...$ini,
    ];

    return PhpConfig::from('fpm-fcgi', fn (string $name) => $ini[$name] ?? false, $displayErrors, $opcacheLoaded, $opcache, $extensions);
}

it('reports the limits, display_errors and the time zone as set', function () {
    expect(phpConfig(displayErrors: 'On'))->toMatchArray([
        'sapi' => 'fpm-fcgi',
        'memory_limit' => '256M',
        'max_execution_time' => 30,
        'upload_max_filesize' => '64M',
        'post_max_size' => '64M',
        'display_errors' => 'On',
        'error_reporting' => 32767,
        'timezone' => 'Europe/Berlin',
    ]);
});

it('reports unset values as null', function () {
    expect(phpConfig(['date.timezone' => '', 'memory_limit' => false, 'error_reporting' => false], displayErrors: null))
        ->toMatchArray(['timezone' => null, 'memory_limit' => null, 'error_reporting' => null, 'display_errors' => null]);
});

it('lists the extensions by lower-case name, each once', function () {
    expect(phpConfig(extensions: ['Core', 'intl', 'Zend OPcache', 'intl'])['extensions'])->toBe(['core', 'intl', 'zend opcache']);
});

it('reports OPcache with its fill level', function () {
    $status = [
        'opcache_enabled' => true,
        'cache_full' => false,
        'memory_usage' => ['used_memory' => 60, 'free_memory' => 30, 'wasted_memory' => 10],
        'opcache_statistics' => ['num_cached_keys' => 900, 'max_cached_keys' => 1000],
    ];

    expect(phpConfig(opcache: $status)['opcache'])->toBe([
        'enabled' => true, 'memory_used' => 70, 'memory_total' => 100, 'keys_used' => 900, 'keys_max' => 1000, 'full' => false,
    ]);
});

it('falls back to the setting when the status is restricted', function () {
    expect(phpConfig(['opcache.enable' => '0'])['opcache'])->toBe([
        'enabled' => false, 'memory_used' => null, 'memory_total' => null, 'keys_used' => null, 'keys_max' => null, 'full' => null,
    ]);
});

it('reports no OPcache when the extension is missing', function () {
    expect(phpConfig(opcacheLoaded: false)['opcache'])->toBeNull();
});

it('reads the running PHP in the operational data', function () {
    $config = app(OperationalData::class)->report()['php_config'];

    expect($config['sapi'])->toBe(PHP_SAPI)
        ->and($config['extensions'])->toContain('core');
});

it('matches the shared schema', function () {
    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $config = app(PhpConfig::class)->report();

    foreach ([$config, phpConfig(opcache: ['opcache_enabled' => true, 'memory_usage' => [], 'opcache_statistics' => []])] as $value) {
        $result = (new Validator)->validate(json_decode(json_encode($value)), $schema->properties->extra->properties->php_config);

        expect($result->isValid())->toBeTrue();
    }
});
