<?php

use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Opis\JsonSchema\Validator;
use StackMonitor\Agent\FailedLogins;

function failedLogins(): ?array
{
    return app(FailedLogins::class)->report();
}

/** A failed login over HTTP from the given address. */
function loginFails(string $ip = '203.0.113.7', bool $knownUser = false): void
{
    app()->instance('request', Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]));
    event(new Failed('web', $knownUser ? new User : null, ['email' => 'jane@example.com', 'password' => 'secret']));
}

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
});

it('reports nothing before the app used Laravel auth', function () {
    expect(failedLogins())->toBeNull();
});

it('notes since when the app logs in with Laravel auth, from the first attempt', function () {
    $this->travelTo('2026-10-03 08:15:00');
    event(new Attempting('web', ['email' => 'jane@example.com'], false));

    $this->travelTo('2026-10-04 09:00:00');
    event(new Attempting('web', ['email' => 'jane@example.com'], false));

    expect(failedLogins())->toBe(['since' => '2026-10-03T08:15:00+00:00', 'hours' => []]);
});

it('counts failed logins per hour, and those of a user that exists', function () {
    $this->travelTo('2026-10-04 08:05:00');
    loginFails();
    loginFails(knownUser: true);
    $this->travelTo('2026-10-04 08:59:59');
    loginFails(knownUser: true);
    $this->travelTo('2026-10-04 10:30:00');
    loginFails();

    expect(failedLogins())->toBe([
        'since' => '2026-10-04T08:05:00+00:00',
        'hours' => [
            ['hour' => '2026-10-04T08:00:00+00:00', 'failed' => 3, 'known' => 2, 'ips' => 1],
            ['hour' => '2026-10-04T10:00:00+00:00', 'failed' => 1, 'known' => 0, 'ips' => 1],
        ],
    ]);
});

it('counts the distinct addresses of an hour without keeping them', function () {
    $this->travelTo('2026-10-04 08:00:00');
    loginFails('203.0.113.7');
    loginFails('203.0.113.7');
    loginFails('198.51.100.23');
    loginFails('2001:db8::1');

    $cached = json_encode(cache()->get(FailedLogins::CACHE_KEY));

    expect(failedLogins()['hours'][0]['ips'])->toBe(3)
        ->and($cached)->not->toContain('203.0.113.7')->not->toContain('198.51.100.23')->not->toContain('2001:db8::1')
        ->and($cached)->toContain(substr(hash_hmac('sha256', '203.0.113.7', (string) config('app.key')), 0, 12));
});

it('keeps only the count of addresses of past hours', function () {
    $this->travelTo('2026-10-04 08:00:00');
    loginFails('203.0.113.7');
    loginFails('198.51.100.23');
    $this->travelTo('2026-10-04 09:00:00');
    loginFails('203.0.113.7');

    $hours = cache()->get(FailedLogins::CACHE_KEY)['hours'];

    expect($hours[strtotime('2026-10-04 08:00:00 UTC')]['ips'])->toBe(2)
        ->and($hours[strtotime('2026-10-04 09:00:00 UTC')]['ips'])->toBeArray()->toHaveCount(1)
        ->and(array_column(failedLogins()['hours'], 'ips'))->toBe([2, 1]);
});

it('counts at most 1000 addresses an hour', function () {
    $this->travelTo('2026-10-04 08:00:00');

    for ($i = 0; $i < FailedLogins::MAX_IPS + 5; $i++) {
        loginFails('10.0.'.intdiv($i, 256).'.'.($i % 256));
    }

    expect(failedLogins()['hours'][0])->toMatchArray(['failed' => FailedLogins::MAX_IPS + 5, 'ips' => FailedLogins::MAX_IPS]);
});

it('keeps the hours of the last 14 days', function () {
    $this->travelTo('2026-09-20 08:00:00');
    loginFails();
    $this->travelTo('2026-09-20 09:00:00');
    loginFails();
    $this->travelTo('2026-10-04 08:30:00');
    loginFails();

    expect(array_column(failedLogins()['hours'], 'hour'))->toBe(['2026-09-20T09:00:00+00:00', '2026-10-04T08:00:00+00:00'])
        ->and(cache()->get(FailedLogins::CACHE_KEY)['hours'])->toHaveCount(2);

    // Read without a new failure, an hour older than 14 days is left out too.
    $this->travelTo('2026-10-04 09:00:00');

    expect(array_column(failedLogins()['hours'], 'hour'))->toBe(['2026-10-04T08:00:00+00:00'])
        ->and(failedLogins()['since'])->toBe('2026-09-20T08:00:00+00:00');
});

it('never lets a broken cache break the login', function () {
    config(['cache.default' => 'broken', 'cache.stores.broken' => ['driver' => 'redis', 'connection' => 'missing']]);

    event(new Attempting('web', ['email' => 'jane@example.com'], false));
    loginFails();

    expect(true)->toBeTrue();
});

it('matches the shared schema', function () {
    $this->travelTo('2026-10-04 08:00:00');
    loginFails('203.0.113.7', knownUser: true);
    $this->travelTo('2026-10-04 09:00:00');
    loginFails('198.51.100.23');

    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode(failedLogins())), $schema->properties->extra->properties->failed_logins);

    expect($result->isValid())->toBeTrue();
});
