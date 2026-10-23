<?php

use StackMonitor\Agent\ErrorLog\LogMessage;

it('cleans messages like the WordPress agent', function (string $raw, string $normalized) {
    expect(LogMessage::normalize($raw))->toBe($normalized);
})->with(function () {
    $cases = json_decode(file_get_contents(__DIR__.'/../../../../schema/log-messages.json'), true)['cases'];

    return array_combine(array_column($cases, 'name'), array_map(fn (array $case) => [$case['raw'], $case['normalized']], $cases));
});

it('cuts off the SQL of a query exception', function (string $raw, string $expected) {
    expect(LogMessage::normalize($raw))->toBe($expected);
})->with([
    'with connection' => [
        'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into users (name, email) values (Max, max@example.com))',
        'SQLSTATE[N]: Integrity constraint violation (SQL: …)',
    ],
    'older format' => ['SQLSTATE[HY000]: General error (SQL: select * from orders where id = 5)', 'SQLSTATE[HY000]: General error (SQL: …)'],
]);

it('keeps a message of a very long line', function () {
    expect(LogMessage::normalize('Boom '.str_repeat('a', 1_000_000)))->toStartWith('Boom aaa')
        ->and(mb_strlen(LogMessage::normalize(str_repeat('/a', 500_000))))->toBeGreaterThan(0);
});

it('masks short and unquoted secrets named in the message', function (string $raw, string $expected) {
    expect(LogMessage::normalize($raw))->toBe($expected);
})->with([
    'password' => ['Connection failed with password=hunter2', 'Connection failed with password=***'],
    'bearer' => ['Request rejected: Authorization: Bearer abc', 'Request rejected: Authorization: Bearer ***'],
    'token' => ['Webhook token: x1y2', 'Webhook token: ***'],
    'invalid token stays' => ['Invalid token', 'Invalid token'],
]);
