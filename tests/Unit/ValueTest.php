<?php

use StackMonitor\Agent\Value;

it('takes a number also as the string .env and PDO give it', function (mixed $value, ?int $expected) {
    expect(Value::int($value, null))->toBe($expected);
})->with([
    'int' => [60, 60],
    'string' => ['60', 60],
    'negative string' => ['-5', -5],
    'string with spaces' => [' 60 ', 60],
    'decimal string' => ['60.7', 60],
    'float' => [1_790_000_000.0, 1_790_000_000],
    'letters' => ['abc', null],
    'number with a unit' => ['60s', null],
    'empty string' => ['', null],
    'bool' => [true, null],
    'null' => [null, null],
    'array' => [[60], null],
    'infinite' => [INF, null],
]);

it('takes the default for what is not a number', function () {
    expect(Value::int('soon', 300))->toBe(300)
        ->and(Value::int(null))->toBe(0)
        ->and(Value::int('300', 60))->toBe(300);
});

it('takes a string as it is and a number in its usual form', function (mixed $value, ?string $expected) {
    expect(Value::string($value, null))->toBe($expected);
})->with([
    'string' => ['stackmonitor/status', 'stackmonitor/status'],
    'empty string' => ['', ''],
    'int' => [3306, '3306'],
    'bool' => [false, null],
    'null' => [null, null],
    'array' => [['a'], null],
]);

it('keeps only the string keys of an array', function () {
    expect(Value::map(['driver' => 'single', 0 => 'x']))->toBe(['driver' => 'single'])
        ->and(Value::map('single'))->toBe([])
        ->and(Value::array('single'))->toBe([])
        ->and(Value::array([1, 2]))->toBe([1, 2]);
});
