<?php

use Illuminate\Support\Facades\Route;
use StackMonitor\Agent\Signature;
use StackMonitor\Agent\Tests\TestCase;

/**
 * @param  list<array<string, mixed>>  $points
 * @return array<string, string>
 */
function reachHeaders(array $points, ?string $signed = null): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $encoded = rtrim(strtr(base64_encode((string) json_encode(['points' => $points])), '+/', '-_'), '=');

    return [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-Reach' => $encoded,
        'X-Monitor-Signature' => Signature::forReachRequest($timestamp, $nonce, $signed ?? $encoded, TestCase::SECRET),
    ];
}

it('answers about routes and config values, signed (#160)', function () {
    Route::post('livewire/update', fn () => 'ok')->name('livewire.update');
    Route::post('admin/lw/update', fn () => 'ok')->name('admin.livewire.handle');
    Route::post('backend/lw', fn () => 'ok')->name('backend.livewire.handle');
    Route::post('admin/upload', fn () => 'ok')->middleware('auth');
    Route::post('files/upload', fn () => 'ok')->name('unisharp.lfm.upload')->middleware('signed');
    config(['app.debug' => true, 'services.demo.mode' => 'legacy', 'services.demo.secret' => 'top-secret']);

    $headers = reachHeaders([
        ['kind' => 'route', 'name' => 'livewire/update'],
        ['kind' => 'route', 'name' => 'admin/upload'],
        ['kind' => 'route', 'name' => 'unisharp.lfm.upload'],
        ['kind' => 'route', 'name' => '_ignition/execute-solution'],
        ['kind' => 'route', 'name' => 'livewire.handle'],
        ['kind' => 'config', 'name' => 'app.debug'],
        ['kind' => 'config', 'name' => 'services.demo.secret'],
        ['kind' => 'config', 'name' => 'services.demo.mode', 'equals' => 'legacy'],
        ['kind' => 'config', 'name' => 'services.demo.mode', 'equals' => 'modern'],
        ['kind' => 'config', 'name' => 'services.missing'],
        ['kind' => 'option', 'name' => 'not-for-laravel'],
    ]);

    $response = $this->get('/stackmonitor/status', $headers)->assertOk();
    $body = (string) $response->getContent();

    expect($response->headers->get('X-Monitor-Signature'))->toBe(Signature::forResponse($body, $headers['X-Monitor-Nonce'], TestCase::SECRET))
        ->and(json_decode($body, true)['points'])->toBe([
            ['kind' => 'route', 'name' => 'livewire/update', 'registered' => true, 'public' => true],
            ['kind' => 'route', 'name' => 'admin/upload', 'registered' => true, 'public' => false],
            ['kind' => 'route', 'name' => 'unisharp.lfm.upload', 'registered' => true, 'public' => false],
            ['kind' => 'route', 'name' => '_ignition/execute-solution', 'registered' => false],
            // Behind a group's name prefix, as Livewire's moved update route.
            ['kind' => 'route', 'name' => 'livewire.handle', 'registered' => true, 'public' => true],
            ['kind' => 'config', 'name' => 'app.debug', 'set' => true, 'truthy' => true],
            ['kind' => 'config', 'name' => 'services.demo.secret', 'set' => true, 'truthy' => true],
            ['kind' => 'config', 'name' => 'services.demo.mode', 'equals' => 'legacy', 'set' => true, 'truthy' => true, 'matches' => true],
            ['kind' => 'config', 'name' => 'services.demo.mode', 'equals' => 'modern', 'set' => true, 'truthy' => true, 'matches' => false],
            ['kind' => 'config', 'name' => 'services.missing', 'set' => false, 'truthy' => false],
        ])
        ->and($body)->not->toContain('top-secret')
        ->and($body)->not->toContain(hash('sha256', 'top-secret'));
});

it('rejects a list of points changed on the way like any unknown route', function () {
    $headers = reachHeaders([['kind' => 'config', 'name' => 'app.debug']], signed: 'something-else');

    $this->get('/stackmonitor/status', $headers)->assertNotFound();
});
