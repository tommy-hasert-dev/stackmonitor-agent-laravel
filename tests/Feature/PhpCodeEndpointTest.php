<?php

use StackMonitor\Agent\Signature;
use StackMonitor\Agent\Tests\TestCase;

/** An app with its own code and a private package, next to a secret. */
function appWithCode(): string
{
    $base = sys_get_temp_dir().'/sm-php-endpoint-'.bin2hex(random_bytes(4));
    mkdir($base.'/app/Models', 0777, true);
    mkdir($base.'/vendor/acme/billing/src', 0777, true);
    file_put_contents($base.'/app/Models/User.php', '<?php class User {}');
    file_put_contents($base.'/vendor/acme/billing/src/Invoice.php', '<?php class Invoice {}');
    file_put_contents($base.'/composer.lock', json_encode(['packages' => [['name' => 'acme/billing', 'version' => '2.1.0']]]));
    file_put_contents($base.'/.env', 'APP_KEY=secret');

    app()->setBasePath($base);

    return $base;
}

/** @return array<string, string> */
function packageHeaders(string $id): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));

    return [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-File' => $id,
        'X-Monitor-Signature' => Signature::forFileRequest($timestamp, $nonce, $id, TestCase::SECRET),
    ];
}

it('answers a package request with 404 while file contents are off', function () {
    appWithCode();

    $this->get('/stackmonitor/status', packageHeaders(hash('sha256', 'app:app')))->assertNotFound();
});

it('sends the PHP files of a reported package as a signed ZIP when file contents are on', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithCode();
    $id = hash('sha256', 'composer:acme/billing');
    $headers = packageHeaders($id);

    $response = $this->get('/stackmonitor/status', $headers);

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $body = $response->getContent();
    $data = json_decode($body, true);
    $archive = base64_decode($data['archive']);
    expect($response->headers->get('X-Monitor-Signature'))
        ->toBe(Signature::forResponse($body, $headers['X-Monitor-Nonce'], TestCase::SECRET))
        ->and(array_keys($data))->toBe(['id', 'fingerprint', 'files', 'bytes', 'sha256', 'archive'])
        ->and($data)->toMatchArray(['id' => $id, 'files' => 1, 'bytes' => 22, 'sha256' => hash('sha256', $archive)])
        ->and(unzipped($archive))->toBe(['src/Invoice.php' => '<?php class Invoice {}']);
});

it('answers with the reason when a package cannot be packed', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    $base = appWithCode();

    for ($i = 0; $i <= 5000; $i++) {
        touch($base.'/app/F'.$i.'.php');
    }

    $id = hash('sha256', 'app:app');

    $this->get('/stackmonitor/status', packageHeaders($id))
        ->assertOk()
        ->assertExactJson(['id' => $id, 'error' => 'too_many_files']);
});

it('sends no package it does not report', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithCode();

    $this->get('/stackmonitor/status', packageHeaders(hash('sha256', 'composer:laravel/framework')))->assertNotFound();
});

it('reports the packages with their ids in the report', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithCode();

    $phpCode = json_decode($this->get('/stackmonitor/status', signedHeaders())->assertOk()->getContent(), true)['extra']['php_code'];

    expect($phpCode['contents'])->toBeTrue()
        ->and(array_column($phpCode['packages'], 'id'))->toBe([hash('sha256', 'app:app'), hash('sha256', 'composer:acme/billing')]);
});
