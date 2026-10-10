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

    /** The most secrets the agent takes, STACKMONITOR_AGENT_SECRET first; more are ignored. */
    private const MAX_SECRETS = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            // The route is registered with Route::any() so every method reaches this
            // middleware instead of Laravel answering with its own 405/200 (which would
            // reveal the endpoint exists). Reject non-GET/HEAD methods the same way an
            // unknown route would be rejected.
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        $timestamp = (string) $request->header('X-Monitor-Timestamp', '');
        $nonce = (string) $request->header('X-Monitor-Nonce', '');
        $signature = (string) $request->header('X-Monitor-Signature', '');
        $maxSkew = (int) config('stackmonitor-agent.max_clock_skew', 300);
        // A request for the content of one suspicious file signs its path hash along.
        $file = (string) $request->header('X-Monitor-File', '');

        $valid = ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= $maxSkew
            && preg_match('/^[a-f0-9]{32}$/', $nonce) === 1
            && ($file === '' || preg_match('/^[a-f0-9]{64}$/', $file) === 1);

        // Each StackMonitor instance signs with its own secret (#223); the one that matches
        // signs the answer too, so no instance learns anything about the others.
        $secret = null;

        foreach ($valid ? self::secrets() : [] as $candidate) {
            $expected = $file === ''
                ? Signature::forRequest($timestamp, $nonce, $candidate)
                : Signature::forFileRequest($timestamp, $nonce, $file, $candidate);

            if (hash_equals($expected, $signature)) {
                $secret = $candidate;
                break;
            }
        }

        if ($secret === null || ! Cache::add('stackmonitor-agent:nonce:'.$nonce, true, $maxSkew * 2 + 1)) {
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

    /**
     * STACKMONITOR_AGENT_SECRET, then the comma-separated STACKMONITOR_AGENT_SECRETS, without
     * the ones shorter than MIN_SECRET_LENGTH, at most MAX_SECRETS.
     *
     * @return list<string>
     */
    private static function secrets(): array
    {
        $secrets = config('stackmonitor-agent.secrets');
        $secrets = is_string($secrets) ? explode(',', $secrets) : (array) $secrets;
        array_unshift($secrets, config('stackmonitor-agent.secret'));

        $secrets = array_filter(
            array_map(fn (mixed $secret) => is_string($secret) ? trim($secret) : '', $secrets),
            fn (string $secret) => strlen($secret) >= self::MIN_SECRET_LENGTH,
        );

        return array_slice(array_values(array_unique($secrets)), 0, self::MAX_SECRETS);
    }
}
