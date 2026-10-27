<?php

namespace StackMonitor\Agent;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Answers StackMonitor's questions about the entry points a known
 * vulnerability sits behind (#160): whether a route is registered and
 * open without a login, whether a config value is on. StackMonitor asks
 * only for the points of packages with an open vulnerability, so no full
 * list of the app's routes leaves it. Config values go out as on/off, or
 * whether they equal the value asked for, never the value itself.
 */
final class Reach
{
    /** The most entry points answered per request. */
    public const MAX_POINTS = 50;

    /**
     * Middleware that keeps out a visitor without a login, a permission or a
     * valid signature; a route behind one of them isn't open.
     */
    private const GUARDS = [Authenticate::class, AuthenticateWithBasicAuth::class, Authorize::class, RequirePassword::class, ValidateSignature::class];

    public function __construct(private Router $router, private Repository $config) {}

    /**
     * The points from the request's header, base64url-encoded JSON; an empty
     * list when it doesn't decode.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function decode(string $header): array
    {
        $json = base64_decode(strtr($header, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ! is_array($data['points'] ?? null)) {
            return [];
        }

        return array_slice(array_values(array_filter($data['points'], is_array(...))), 0, self::MAX_POINTS);
    }

    /**
     * @param  list<array<array-key, mixed>>  $points
     * @return array{points: list<array<string, mixed>>}
     */
    public function answer(array $points): array
    {
        $answers = [];

        foreach ($points as $point) {
            $kind = is_string($point['kind'] ?? null) ? $point['kind'] : '';
            $name = is_string($point['name'] ?? null) ? $point['name'] : '';
            $equals = is_string($point['equals'] ?? null) && strlen($point['equals']) <= 200 ? $point['equals'] : null;

            if ($name === '' || strlen($name) > 200) {
                continue;
            }

            $answer = match ($kind) {
                'route' => $this->route($name),
                'config' => $this->config($name, $equals),
                default => null,
            };

            if ($answer !== null) {
                $answers[] = ['kind' => $kind, 'name' => $name, ...($equals !== null && $kind === 'config' ? ['equals' => $equals] : []), ...$answer];
            }
        }

        return ['points' => $answers];
    }

    /**
     * Whether a route with that URI or name is registered, and whether one
     * of them lets a visitor in without a login. A name also matches behind
     * a group's prefix (`admin.livewire.update`). Middleware the agent
     * doesn't know counts as open.
     *
     * @return array{registered: bool, public?: bool}
     */
    private function route(string $name): array
    {
        $uri = trim($name, '/');
        $matches = array_filter(
            $this->router->getRoutes()->getRoutes(),
            fn (Route $route): bool => trim($route->uri(), '/') === $uri
                || $route->getName() === $name || str_ends_with((string) $route->getName(), '.'.$name),
        );

        if ($matches === []) {
            return ['registered' => false];
        }

        $public = false;

        foreach ($matches as $route) {
            $public = $public || ! $this->guarded($route);
        }

        return ['registered' => true, 'public' => $public];
    }

    private function guarded(Route $route): bool
    {
        $middleware = $this->router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $class = explode(':', $entry, 2)[0];

            foreach (self::GUARDS as $guard) {
                if (is_a($class, $guard, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a config value is set and on, and whether it equals the value
     * asked for. "false", "off", "no" and "0" count as off, the way `.env`
     * gives them.
     *
     * @return array{set: bool, truthy: bool, matches?: bool}
     */
    private function config(string $name, ?string $equals): array
    {
        if (! $this->config->has($name)) {
            return ['set' => false, 'truthy' => false, ...($equals !== null ? ['matches' => false] : [])];
        }

        $value = $this->config->get($name);
        $answer = [
            'set' => true,
            'truthy' => is_string($value) ? ! in_array(strtolower(trim($value)), ['', '0', 'no', 'off', 'false'], true) : (bool) $value,
        ];

        if ($equals !== null) {
            $answer['matches'] = is_scalar($value) && (string) $value === $equals;
        }

        return $answer;
    }
}
