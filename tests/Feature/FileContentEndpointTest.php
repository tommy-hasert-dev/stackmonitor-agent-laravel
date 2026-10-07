<?php

use StackMonitor\Agent\Signature;
use StackMonitor\Agent\SuspiciousFiles;
use StackMonitor\Agent\Tests\TestCase;

/** An app with one web shell on the public disk, scanned once. */
function appWithShell(): string
{
    $base = sys_get_temp_dir().'/sm-endpoint-'.bin2hex(random_bytes(4));
    mkdir($base.'/storage/app/public', 0777, true);
    file_put_contents($base.'/storage/app/public/shell.php', '<?php system($_GET["c"]);');
    file_put_contents($base.'/.env', 'APP_KEY=secret');

    app()->setBasePath($base);
    app()->useStoragePath($base.'/storage');
    app()->usePublicPath($base.'/public');
    config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $base.'/storage/app/public']]);
    app(SuspiciousFiles::class)->report();

    return $base;
}

/** @return array<string, string> */
function fileHeaders(string $path, bool $signHash = true): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $hash = hash('sha256', $path);

    return [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-File' => $hash,
        'X-Monitor-Signature' => $signHash
            ? Signature::forFileRequest($timestamp, $nonce, $hash, TestCase::SECRET)
            : Signature::forRequest($timestamp, $nonce, TestCase::SECRET),
    ];
}

it('answers a file request with 404 while file contents are off', function () {
    appWithShell();

    $this->get('/stackmonitor/status', fileHeaders('storage/app/public/shell.php'))->assertNotFound();
});

it('sends the start of a reported file, signed, when file contents are on', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithShell();
    $headers = fileHeaders('storage/app/public/shell.php');

    $response = $this->get('/stackmonitor/status', $headers);

    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $body = $response->getContent();
    expect($response->headers->get('X-Monitor-Signature'))
        ->toBe(Signature::forResponse($body, $headers['X-Monitor-Nonce'], TestCase::SECRET))
        ->and(base64_decode(json_decode($body, true)['content']))->toBe('<?php system($_GET["c"]);')
        ->and(json_decode($body, true)['path_hash'])->toBe($headers['X-Monitor-File']);
});

it('rejects a file request signed without the path hash', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithShell();

    $this->get('/stackmonitor/status', fileHeaders('storage/app/public/shell.php', signHash: false))->assertNotFound();
});

it('rejects a malformed path hash', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithShell();
    $headers = fileHeaders('storage/app/public/shell.php');
    $headers['X-Monitor-File'] = '../../.env';
    $headers['X-Monitor-Signature'] = Signature::forFileRequest($headers['X-Monitor-Timestamp'], $headers['X-Monitor-Nonce'], '../../.env', TestCase::SECRET);

    $this->get('/stackmonitor/status', $headers)->assertNotFound();
});

it('sends no file the scan did not report', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithShell();

    $this->get('/stackmonitor/status', fileHeaders('.env'))->assertNotFound();
});

it('still sends the report without the file header', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    appWithShell();

    $response = $this->get('/stackmonitor/status', signedHeaders());

    $response->assertOk();
    expect(json_decode($response->getContent(), true)['extra']['suspicious_files']['contents'])->toBeTrue();
});
