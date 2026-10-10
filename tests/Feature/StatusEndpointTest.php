<?php

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\Signature;
use StackMonitor\Agent\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

it('reports the scheduled tasks when only the console kernel registers the schedule', function () {
    // Laravel 11+ apps schedule in routes/console.php, which HTTP requests don't load.
    app(Schedule::class)->call(fn () => null)->name('Bereinigen')->everyMinute();
    app(Schedule::class)->command('inspire')->daily();
    // The console kernel fires CommandStarting, $this->artisan() doesn't.
    event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
    $this->artisan('schedule:run');
    app()->instance(Schedule::class, new Schedule);

    $body = $this->get('/stackmonitor/status', signedHeaders())->assertOk()->getContent();

    $schema = file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json');
    $extra = json_decode($body, true)['extra'];
    expect((new Validator)->validate(json_decode($body), $schema)->isValid())->toBeTrue()
        ->and($extra['scheduler']['tasks'])->toBe(2)
        ->and(array_column($extra['scheduled_tasks'], 'command'))->toBe(['Bereinigen', 'inspire'])
        ->and($extra['scheduled_tasks'][0]['last_run']['status'])->toBe('ok');
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

const OTHER_SECRET = 'a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b1a0f9e8';

it('answers every instance with its own secret from STACKMONITOR_AGENT_SECRETS', function () {
    config(['stackmonitor-agent.secrets' => ' '.OTHER_SECRET.' , '.str_repeat('b', 64)]);

    foreach ([TestCase::SECRET, OTHER_SECRET, str_repeat('b', 64)] as $secret) {
        $headers = signedHeaders(secret: $secret);
        $response = $this->get('/stackmonitor/status', $headers)->assertOk();

        expect($response->headers->get('X-Monitor-Signature'))
            ->toBe(Signature::forResponse($response->getContent(), $headers['X-Monitor-Nonce'], $secret));
    }
});

it('takes the secrets alone, without STACKMONITOR_AGENT_SECRET', function () {
    config(['stackmonitor-agent.secret' => null, 'stackmonitor-agent.secrets' => OTHER_SECRET]);

    $this->get('/stackmonitor/status', signedHeaders(secret: OTHER_SECRET))->assertOk();
    $this->get('/stackmonitor/status', signedHeaders())->assertNotFound();
});

it('skips a secret shorter than 32 characters but keeps the others', function () {
    config(['stackmonitor-agent.secrets' => 'changeme,'.OTHER_SECRET]);

    $this->get('/stackmonitor/status', signedHeaders(secret: 'changeme'))->assertNotFound();
    $this->get('/stackmonitor/status', signedHeaders(secret: OTHER_SECRET))->assertOk();
});

it('takes at most five secrets', function () {
    $secrets = array_map(fn (int $i) => str_repeat((string) $i, 64), range(1, 5));
    config(['stackmonitor-agent.secrets' => implode(',', $secrets)]);

    // STACKMONITOR_AGENT_SECRET comes first, so the fifth of the list is one too many.
    $this->get('/stackmonitor/status', signedHeaders(secret: $secrets[3]))->assertOk();
    $this->get('/stackmonitor/status', signedHeaders(secret: $secrets[4]))->assertNotFound();
});

it('lets a nonce through only once, whichever secret signed it', function () {
    config(['stackmonitor-agent.secrets' => OTHER_SECRET]);
    $nonce = bin2hex(random_bytes(16));

    $this->get('/stackmonitor/status', signedHeaders(nonce: $nonce))->assertOk();
    $this->get('/stackmonitor/status', signedHeaders(nonce: $nonce, secret: OTHER_SECRET))->assertNotFound();
});

it('does not start a session', function () {
    $this->get('/stackmonitor/status', signedHeaders())->assertCookieMissing(config('session.cookie'));
});
