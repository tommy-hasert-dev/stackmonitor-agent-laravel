<?php

use Illuminate\Support\Facades\Route;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\Signature;
use StackMonitor\Agent\Tests\TestCase;

/**
 * @return array<string, string>
 */
function signedHeaders(?int $timestamp = null, ?string $nonce = null, string $secret = TestCase::SECRET): array
{
    $timestamp = (string) ($timestamp ?? time());
    $nonce ??= bin2hex(random_bytes(16));

    return [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-Signature' => Signature::forRequest($timestamp, $nonce, $secret),
    ];
}

/**
 * Headers where the signature was computed for a different timestamp than the one sent.
 *
 * @return array<string, string>
 */
function tamperedTimestampHeaders(): array
{
    $nonce = bin2hex(random_bytes(16));
    $signedTimestamp = (string) time();
    $sentTimestamp = (string) (time() + 5);

    return [
        'X-Monitor-Timestamp' => $sentTimestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-Signature' => Signature::forRequest($signedTimestamp, $nonce, TestCase::SECRET),
    ];
}

it('returns a signed report that matches the shared schema', function () {
    $headers = signedHeaders();

    $response = $this->get('/stackmonitor/status', $headers);

    $response->assertOk();
    $body = $response->getContent();
    expect($response->headers->get('X-Monitor-Signature'))
        ->toBe(Signature::forResponse($body, $headers['X-Monitor-Nonce'], TestCase::SECRET));

    $schema = file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json');
    $result = (new Validator)->validate(json_decode($body), $schema);
    expect($result->isValid())->toBeTrue();
    expect(json_decode($body, true)['extra'])->toHaveKeys(['scheduler', 'disk', 'migrations_pending', 'config_cached', 'routes_cached']);
});

it('rejects invalid requests with 404', function (Closure $headers) {
    $this->get('/stackmonitor/status', $headers())->assertNotFound();
})->with([
    'no headers' => [fn () => []],
    'wrong secret' => [fn () => signedHeaders(secret: 'wrong')],
    'stale timestamp' => [fn () => signedHeaders(timestamp: time() - 301)],
    'future timestamp' => [fn () => signedHeaders(timestamp: time() + 301)],
    'malformed nonce' => [fn () => signedHeaders(nonce: 'xyz')],
    'uppercase-hex nonce' => [fn () => signedHeaders(nonce: strtoupper(bin2hex(random_bytes(16))))],
    'tampered timestamp' => [fn () => tamperedTimestampHeaders()],
]);

it('rejects requests signed with a secret shorter than 32 characters', function () {
    config(['stackmonitor-agent.secret' => 'changeme']);

    $this->get('/stackmonitor/status', signedHeaders(secret: 'changeme'))->assertNotFound();
});

it('returns a 404 indistinguishable from an unknown route', function (string $method) {
    config(['app.debug' => false]);

    $rejected = $this->json($method, '/stackmonitor/status');
    $unknown = $this->json($method, '/this-route-does-not-exist-at-all');

    $rejected->assertNotFound();
    $unknown->assertNotFound();

    expect(array_keys($rejected->json()))->toBe(array_keys($unknown->json()));
    expect($rejected->json('message'))->toMatch('/^The route .+ could not be found\.$/');
    expect($rejected->headers->has('X-Monitor-Signature'))->toBeFalse();
})->with([
    'GET' => ['GET'],
    'POST' => ['POST'],
    'OPTIONS' => ['OPTIONS'],
    // Outside Route::any()'s verbs: Laravel answers these with its own 405 (#18).
    'PROPFIND' => ['PROPFIND'],
    'TRACE' => ['TRACE'],
    'made-up method' => ['FOO'],
]);

it('keeps the 405 for unusual methods on routes of the app itself', function () {
    Route::get('/app-route', fn () => 'ok');

    $this->json('PROPFIND', '/app-route')->assertStatus(405);
});

it('ignores an unknown _sm cache-buster query parameter (M4)', function () {
    $headers = signedHeaders();

    $this->get('/stackmonitor/status?_sm=deadbeefdeadbeefdeadbeefdeadbeef', $headers)->assertOk();
});

it('rejects replayed nonces', function () {
    $headers = signedHeaders();

    $this->get('/stackmonitor/status', $headers)->assertOk();
    $this->get('/stackmonitor/status', $headers)->assertNotFound();
});

it('is disabled without a configured secret', function () {
    config(['stackmonitor-agent.secret' => null]);

    $this->get('/stackmonitor/status', signedHeaders())->assertNotFound();
});

it('does not start a session', function () {
    $this->get('/stackmonitor/status', signedHeaders())->assertCookieMissing(config('session.cookie'));
});
