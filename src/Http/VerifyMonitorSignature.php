<?php

namespace StackMonitor\Agent\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use StackMonitor\Agent\Signature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VerifyMonitorSignature
{
    private const MIN_SECRET_LENGTH = 32;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            // The route is registered with Route::any() so every method reaches this
            // middleware instead of Laravel answering with its own 405/200 (which would
            // reveal the endpoint exists). Reject non-GET/HEAD methods the same way an
            // unknown route would be rejected.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        $secret = config('stackmonitor-agent.secret');
        $timestamp = (string) $request->header('X-Monitor-Timestamp', '');
        $nonce = (string) $request->header('X-Monitor-Nonce', '');
        $signature = (string) $request->header('X-Monitor-Signature', '');
        $maxSkew = (int) config('stackmonitor-agent.max_clock_skew', 300);

        $valid = is_string($secret) && strlen($secret) >= self::MIN_SECRET_LENGTH
            && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= $maxSkew
            && preg_match('/^[a-f0-9]{32}$/', $nonce) === 1
            && hash_equals(Signature::forRequest($timestamp, $nonce, $secret), $signature);

        if (! $valid || ! Cache::add('stackmonitor-agent:nonce:'.$nonce, true, $maxSkew * 2 + 1)) {
            // Deliberately the exact exception (and message) Laravel's router throws for an
            // unknown route, so a rejected agent request cannot be told apart from a 404 on
            // any other, unrelated path (spec §7: "generisches 404, damit der Endpoint nach
            // außen nicht auffällt").
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        $response = $next($request);
        $response->headers->set('X-Monitor-Signature', Signature::forResponse((string) $response->getContent(), $nonce, $secret));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
