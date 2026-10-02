<?php

use StackMonitor\Agent\ErrorLog\LogMessage;

it('replaces what can identify people or secrets', function (string $raw, string $expected) {
    expect(LogMessage::normalize($raw))->toBe($expected);
})->with([
    'email' => ['Mail to max.mustermann@example.com failed', 'Mail to <email> failed'],
    'url with token' => ['cURL error for https://api.example.com/v1?token=abc123', 'cURL error for <url>'],
    'quoted values' => ["Duplicate entry 'max@example.com' for key 'users_email_unique'", "Duplicate entry '?' for key '?'"],
    'double quotes' => ['Unknown column "secret_value" in field list', 'Unknown column "?" in field list'],
    'apostrophe in words stays' => ["Can't connect to server", "Can't connect to server"],
    'uuid' => ['Order 3f2b8c1e-4d5a-4b6c-9e7f-0a1b2c3d4e5f not found', 'Order <uuid> not found'],
    'token' => ['Invalid API key sk_live_51HxQ2bC8dE9fG0hI1jK2', 'Invalid API key <token>'],
    'ip' => ['Connection refused from 192.168.10.25', 'Connection refused from <ip>'],
    'numbers' => ['Undefined array key 42 on line 17', 'Undefined array key N on line N'],
    'path keeps the file name' => ['include(/var/www/html/app/Http/Kernel.php): Failed to open stream', 'include(…/Kernel.php): Failed to open stream'],
    'sql of a query exception' => [
        'SQLSTATE[23000]: Integrity constraint violation (Connection: mysql, SQL: insert into users (name, email) values (Max, max@example.com))',
        'SQLSTATE[N]: Integrity constraint violation (SQL: …)',
    ],
    'older sql format' => ['SQLSTATE[HY000]: General error (SQL: select * from orders where id = 5)', 'SQLSTATE[HY000]: General error (SQL: …)'],
]);

it('keeps one line with collapsed whitespace', function () {
    expect(LogMessage::normalize("  Fatal error:   Uncaught Error\n#0 trace  "))->toBe('Fatal error: Uncaught Error #N trace');
});

it('keeps a message of a very long line', function () {
    expect(LogMessage::normalize('Boom '.str_repeat('a', 1_000_000)))->toStartWith('Boom aaa')
        ->and(mb_strlen(LogMessage::normalize(str_repeat('/a', 500_000))))->toBeGreaterThan(0);
});

it('caps the message at 200 characters', function () {
    expect(mb_strlen(LogMessage::normalize(str_repeat('ä', 500))))->toBe(200);
});
