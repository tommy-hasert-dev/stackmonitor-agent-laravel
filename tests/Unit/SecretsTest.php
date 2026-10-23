<?php

use StackMonitor\Agent\Secrets;

it('masks the secrets of a config in a message, also URL-encoded', function () {
    $secrets = (new Secrets)->collect([
        'mysql' => ['username' => 'shopuser', 'password' => 'p@ss w/rd'],
        'stripe' => ['client_secret' => 'sk_live_abc', 'webhook_secret' => 'whsec_1'],
        'sftp' => ['passphrase' => 'phrase1', 'private_key' => 'keydata1', 'privatekey' => 'keydata2'],
        'short' => ['password' => 'abc'],
    ]);

    expect($secrets->clean('shopuser failed with p@ss w/rd, p%40ss%20w%2Frd and p%40ss+w%2Frd', 300))
        ->toBe('*** failed with ***, *** and ***')
        ->and($secrets->clean('sk_live_abc whsec_1 phrase1 keydata1 keydata2 abc', 300))->toBe('*** *** *** *** *** abc');
});

it('masks a URL in the config only when it carries credentials', function () {
    $secrets = (new Secrets)->collect(['app' => ['url' => 'https://shop.example'], 'redis' => ['url' => 'redis://:pw1234@redis:6379']]);

    expect($secrets->clean('https://shop.example and redis://:pw1234@redis:6379', 300))
        ->toBe('https://shop.example and ***');
});

it('takes the secrets of the whole app config', function () {
    config(['services.acme.api_key' => 'acme-key-123', 'mail.mailers.smtp.username' => 'mailuser']);

    expect(Secrets::fromConfig()->clean('GET https://api.acme.test/v1?key=acme-key-123 as mailuser', 300))
        ->toBe('GET https://api.acme.test/v1?key=*** as ***');
});

it('masks values named as a secret in the text', function (string $message, string $masked) {
    expect(Secrets::named($message))->toBe($masked);
})->with([
    'unquoted password' => ['Login failed: password=hunter2 user=max', 'Login failed: password=*** user=max'],
    'colon' => ['token: abc', 'token: ***'],
    'json' => ['{"password":"hunter2","user":"max"}', '{"password":***,"user":"max"}'],
    'query' => ['GET /v1?api_key=abc&page=2', 'GET /v1?api_key=***&page=2'],
    'own name' => ['STRIPE_SECRET=sk_1 and x-api-key: k1', 'STRIPE_SECRET=*** and x-api-key: ***'],
    'bearer' => ['Authorization: Bearer abc.def', 'Authorization: Bearer ***'],
    'basic' => ['Authorization: Basic dXNlcjpwdw==', 'Authorization: Basic ***'],
    'bearer alone' => ['sent bearer xyz', 'sent bearer ***'],
    'invalid token' => ['Invalid token', 'Invalid token'],
    'token mismatch' => ['CSRF token mismatch.', 'CSRF token mismatch.'],
    'bearer token missing' => ['Bearer token missing', 'Bearer token missing'],
    'authorization failed' => ['Authorization failed for user 5', 'Authorization failed for user 5'],
    'password reset' => ['The password reset link expired', 'The password reset link expired'],
]);
