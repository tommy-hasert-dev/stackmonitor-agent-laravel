<?php

namespace StackMonitor\Agent\ErrorLog;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;
use Throwable;

/**
 * Counts the entries from level ERROR up that the app logged in the last 24
 * hours, and its most frequent messages. Only file logs can be read: the
 * default channel if it is `single` or `daily`, or the first such channel in
 * a stack; anything else (stderr, Sentry, Papertrail, …) is reported as not
 * evaluable. The log is read from its end, at most READ_LIMIT bytes, so a
 * big log doesn't slow the report down.
 *
 * @phpstan-type Report array{status: string, reason: string|null, source: string|null, errors: int|null, warnings: null, truncated: bool, top: list<array{message: string, level: string, count: int}>|null}
 */
final class ErrorLog
{
    public const WINDOW_SECONDS = 86_400;

    public const READ_LIMIT = 5 * 1024 * 1024;

    public const TOP = 3;

    /** Read in blocks, so a big log never sits in memory as a whole. */
    public const BLOCK = 65_536;

    /** Different messages kept for the top list; more are only counted. */
    public const MAX_DISTINCT = 500;

    private const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    private const LINE = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\] \S+?\.([A-Z]+): (.*)$/';

    /**
     * @param  array<string, mixed>  $channels  config('logging.channels')
     */
    public function __construct(
        private readonly array $channels,
        private readonly string $default,
        private readonly string $timezone,
        private readonly bool $messages,
    ) {}

    /**
     * @return Report
     */
    public function report(?int $now = null): array
    {
        $now ??= time();
        $channel = $this->fileChannel($this->default, []);

        if ($channel === null) {
            return $this->unavailable('no_file');
        }

        $path = is_string($channel['path'] ?? null) && $channel['path'] !== '' ? $channel['path'] : storage_path('logs/laravel.log');

        try {
            [$files, $source] = $channel['driver'] === 'daily' ? $this->dailyFiles($path, $now) : [[$path], basename($path)];

            return $this->scan($files, $source, $now - self::WINDOW_SECONDS);
        } catch (Throwable) {
            return $this->unavailable('not_readable');
        }
    }

    /**
     * The first `single` or `daily` channel the given one writes to.
     *
     * @param  list<string>  $seen
     * @return array<string, mixed>|null
     */
    private function fileChannel(string $name, array $seen): ?array
    {
        $config = $this->channels[$name] ?? null;

        if (! is_array($config) || in_array($name, $seen, true)) {
            return null;
        }

        $driver = $config['driver'] ?? null;

        if ($driver === 'single' || $driver === 'daily') {
            return $config;
        }

        if ($driver !== 'stack') {
            return null;
        }

        $inner = $config['channels'] ?? [];
        $inner = is_string($inner) ? explode(',', $inner) : (array) $inner;

        foreach ($inner as $child) {
            $found = is_string($child) ? $this->fileChannel(trim($child), [...$seen, $name]) : null;

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Today's and yesterday's file, the way Monolog's RotatingFileHandler names
     * them: `laravel.log` becomes `laravel-2026-10-02.log`.
     *
     * @return array{list<string>, string}
     */
    private function dailyFiles(string $path, int $now): array
    {
        $info = pathinfo($path);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
        $base = ($info['dirname'] ?? '.').'/'.$info['filename'].'-';
        $today = (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone($this->timezone));

        return [
            [$base.$today->format('Y-m-d').$extension, $base.$today->modify('-1 day')->format('Y-m-d').$extension],
            $info['filename'].'-*'.$extension,
        ];
    }

    /**
     * Walks the files newest first, each from its end, until an entry is older
     * than $since or the read limit is used up.
     *
     * @param  list<string>  $files
     * @return Report
     */
    private function scan(array $files, string $source, int $since): array
    {
        if (! @is_dir(dirname($files[0]))) {
            return $this->unavailable('not_readable');
        }

        $budget = self::READ_LIMIT;
        $counts = [];
        $errors = 0;
        $truncated = false;
        $timezone = new DateTimeZone($this->timezone);

        foreach ($files as $i => $file) {
            if (! @is_file($file)) {
                continue;
            }

            if ($budget === 0) {
                $truncated = (int) @filesize($file) > 0;
                break;
            }

            $partial = false;

            foreach ($this->linesFromEnd($file, $budget, $partial) as $line) {
                if (preg_match(self::LINE, $line, $match) !== 1) {
                    continue;
                }

                if ((new DateTimeImmutable($match[1], $timezone))->getTimestamp() < $since) {
                    return $this->result($source, $errors, false, $counts);
                }

                if (in_array($match[2], self::LEVELS, true)) {
                    $errors++;
                    $message = LogMessage::normalize($this->withoutContext($match[3])) ?: '–';

                    if (isset($counts[$message]) || count($counts) < self::MAX_DISTINCT) {
                        $counts[$message] = ($counts[$message] ?? 0) + 1;
                    }
                }
            }

            if ($partial) {
                $truncated = true;
                break;
            }
        }

        return $this->result($source, $errors, $truncated, $counts);
    }

    /**
     * The lines of a file from its end, newest first, read in blocks within
     * the budget. $partial tells, once read, that the budget ended before the
     * file did; its first, cut line is dropped then.
     *
     * @return Generator<int, string>
     */
    private function linesFromEnd(string $file, int &$budget, bool &$partial): Generator
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new RuntimeException('not readable');
        }

        try {
            $size = (int) fstat($handle)['size'];
            $start = $size - min($size, $budget);
            $budget -= $size - $start;
            $partial = $start > 0;
            $position = $size;
            // The start of the oldest line read so far, completed by the next block.
            $carry = '';

            while ($position > $start) {
                $length = min(self::BLOCK, $position - $start);
                $position -= $length;
                fseek($handle, $position);
                $lines = explode("\n", fread($handle, $length).$carry);
                $carry = (string) array_shift($lines);

                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    yield $lines[$i];
                }
            }

            if (! $partial) {
                yield $carry;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Monolog appends the context and extra data as JSON; they may hold
     * request data and the exception with its paths, so they stay behind.
     */
    private function withoutContext(string $message): string
    {
        return (string) preg_replace('/ (?:\{".*|\[\]|\{\})$/', '', $message);
    }

    /**
     * @param  array<string, int>  $counts
     * @return Report
     */
    private function result(string $source, int $errors, bool $truncated, array $counts): array
    {
        $top = null;

        if ($this->messages) {
            arsort($counts);
            $top = [];

            foreach (array_slice($counts, 0, self::TOP, true) as $message => $count) {
                $top[] = ['message' => (string) $message, 'level' => 'error', 'count' => $count];
            }
        }

        return [
            'status' => 'ok',
            'reason' => null,
            'source' => $source,
            'errors' => $errors,
            'warnings' => null,
            'truncated' => $truncated,
            'top' => $top,
        ];
    }

    /**
     * @return Report
     */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'source' => null,
            'errors' => null,
            'warnings' => null,
            'truncated' => false,
            'top' => null,
        ];
    }
}
