<?php

namespace StackMonitor\Agent;

use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Failed logins per hour (#145), so the dashboard can show their history and
 * flag a spike. The agent counts Laravel's auth events in the app's cache:
 * the failures, those for a user that exists (the password was wrong) and
 * the distinct addresses they came from. Only counts leave the app, no
 * addresses or user names.
 *
 * Token guards such as Sanctum's fire no such events; an app that never
 * logs in with Laravel's auth is reported as null, not as zero failures.
 *
 * Two attempts at the same moment may race and lose a count now and then;
 * the numbers are for trends, a lock on every login isn't worth it.
 */
final class FailedLogins
{
    public const CACHE_KEY = 'stackmonitor-agent:failed-logins';

    /** Days of hourly counts kept. */
    public const KEPT_DAYS = 14;

    /** Distinct addresses counted per hour at most. */
    public const MAX_IPS = 1000;

    public function __construct(private readonly Application $app) {}

    /**
     * Notes since when the app logs in with Laravel's auth, and counts a
     * failure into the current hour. A broken cache must never break the
     * login, so it fails silently.
     */
    public function record(object $event): void
    {
        $this->quietly(function () use ($event) {
            $state = $this->cache()->get(self::CACHE_KEY);
            $state = is_array($state) ? $state : [];
            $now = now()->getTimestamp();

            if (! $event instanceof Failed) {
                if (! is_int($state['since'] ?? null)) {
                    $this->cache()->forever(self::CACHE_KEY, [...$state, 'since' => $now, 'hours' => $state['hours'] ?? []]);
                }

                return;
            }

            $hour = intdiv($now, 3600) * 3600;
            $hours = [];

            // Older hours keep only their number of addresses.
            foreach ((array) ($state['hours'] ?? []) as $start => $counts) {
                if (is_int($start) && $start > $this->cutoff($now) && is_array($counts)) {
                    $hours[$start] = $start === $hour ? $counts : [...$counts, 'ips' => $this->ips($counts)];
                }
            }

            $counts = $hours[$hour] ?? [];
            $ips = is_array($counts['ips'] ?? null) ? $counts['ips'] : [];
            $ip = $this->hashedIp();

            if ($ip !== null && count($ips) < self::MAX_IPS && ! in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }

            $hours[$hour] = [
                'failed' => (int) ($counts['failed'] ?? 0) + 1,
                'known' => (int) ($counts['known'] ?? 0) + ($event->user !== null ? 1 : 0),
                'ips' => $ips,
            ];

            $this->cache()->forever(self::CACHE_KEY, [
                'since' => is_int($state['since'] ?? null) ? $state['since'] : $now,
                'hours' => $hours,
            ]);
        });
    }

    /**
     * The hours with failed logins of the last 14 days, oldest first; null
     * while the app hasn't used Laravel's auth since the agent came.
     *
     * @return array{since: string, hours: list<array{hour: string, failed: int, known: int, ips: int}>}|null
     */
    public function report(): ?array
    {
        $state = $this->cache()->get(self::CACHE_KEY);

        if (! is_array($state) || ! is_int($state['since'] ?? null)) {
            return null;
        }

        $hours = (array) ($state['hours'] ?? []);
        ksort($hours);
        $cutoff = $this->cutoff(now()->getTimestamp());
        $report = [];

        foreach ($hours as $start => $counts) {
            if (! is_int($start) || $start <= $cutoff || ! is_array($counts) || (int) ($counts['failed'] ?? 0) <= 0) {
                continue;
            }

            $report[] = [
                'hour' => date(DATE_ATOM, $start),
                'failed' => (int) $counts['failed'],
                'known' => max(0, (int) ($counts['known'] ?? 0)),
                'ips' => $this->ips($counts),
            ];
        }

        return ['since' => date(DATE_ATOM, $state['since']), 'hours' => $report];
    }

    /** The start of the newest hour no longer kept. */
    private function cutoff(int $now): int
    {
        return intdiv($now, 3600) * 3600 - self::KEPT_DAYS * 86400;
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function ips(array $counts): int
    {
        $ips = $counts['ips'] ?? 0;

        return is_array($ips) ? count($ips) : max(0, (int) $ips);
    }

    /**
     * The request's address as a short keyed hash, enough to tell addresses
     * apart within an hour. Not runningInConsole(): under Octane every
     * request runs in the console.
     */
    private function hashedIp(): ?string
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $ip = $this->app['request']->ip();

        return is_string($ip) && $ip !== '' ? substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 12) : null;
    }

    private function cache(): Repository
    {
        return $this->app['cache']->store();
    }

    private function quietly(callable $write): void
    {
        try {
            $write();
        } catch (Throwable) {
            // Nothing counted then; the numbers are for trends.
        }
    }
}
