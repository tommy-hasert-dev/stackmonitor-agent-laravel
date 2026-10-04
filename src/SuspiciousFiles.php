<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Files that let PHP run where only uploads belong (#144), sent in
 * `extra.suspicious_files`: a PHP file there is one of the most common signs
 * of a successful attack (a web shell through a vulnerable upload). The agent
 * looks through the public disk (`storage/app/public`) and `public/storage`,
 * when that is a folder of its own rather than the usual link, for PHP
 * extensions, a PHP extension hidden before the last one (`bild.php.jpg`) and
 * `.htaccess` files that let PHP run. Path, size and date leave the app,
 * never the content. An `index.php` that is empty or only a comment is left
 * out.
 *
 * The scan runs during the report, but within MAX_ENTRIES and MAX_SECONDS: a
 * large folder is looked through over several reports, which send the last
 * complete result meanwhile, kept in the app's cache. A new scan starts once
 * that is RESCAN_AFTER old.
 */
final class SuspiciousFiles
{
    public const CACHE_KEY = 'stackmonitor-agent:suspicious-files';

    /** The most files the report schema takes; beyond, only `total` counts them. */
    public const MAX = 200;

    /** Directory entries looked at per report at most. */
    public const MAX_ENTRIES = 50000;

    /** Seconds spent per report at most, checked between directories. */
    public const MAX_SECONDS = 2.0;

    /** Seconds after which a complete result is scanned again. */
    public const RESCAN_AFTER = 1800;

    /** Extensions a web server may hand to PHP. */
    public const EXTENSIONS = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps'];

    /** The largest index.php still read to tell whether it is only a placeholder. */
    private const MAX_PLACEHOLDER = 2048;

    /** The most of an .htaccess file read. */
    private const MAX_HTACCESS = 65536;

    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * Advances the scan and returns the last complete result, null before
     * the first one.
     *
     * @return array{scanned_at: string, scanned: int, total: int, files: list<array{path: string, kind: string, size: int, modified_at: string|null}>}|null
     */
    public function report(): ?array
    {
        $state = $this->cache()->get(self::CACHE_KEY);
        $state = self::scan(
            is_array($state) ? $state : [],
            $this->roots(),
            $this->basePath ?? $this->app->basePath(),
            now()->getTimestamp(),
            self::MAX_ENTRIES,
            microtime(true) + self::MAX_SECONDS,
        );

        $this->cache()->forever(self::CACHE_KEY, $state);

        return self::forReport($state);
    }

    /**
     * The public disk's folder, and `public/storage` unless it only links
     * there.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $disk = config('filesystems.disks.public');
        $public = is_array($disk) && ($disk['driver'] ?? null) === 'local' && is_string($disk['root'] ?? null)
            ? $disk['root']
            : $this->app->storagePath('app/public');
        $roots = [rtrim($public, '/')];
        $link = $this->app->publicPath('storage');

        if (is_dir($link) && realpath($link) !== realpath($public)) {
            $roots[] = rtrim($link, '/');
        }

        return $roots;
    }

    /**
     * Continues the scan in progress, or starts one when the last result is
     * RESCAN_AFTER old, until the queue is empty or a limit is reached; a
     * finished scan becomes the last result.
     *
     * @param  array<string, mixed>  $state  as cached
     * @param  list<string>  $roots  the folders to look through
     * @param  string  $base  paths are reported relative to it
     * @return array<string, mixed>
     */
    public static function scan(array $state, array $roots, string $base, int $now, int $maxEntries, float $deadline): array
    {
        $run = is_array($state['run'] ?? null) ? $state['run'] : null;

        if ($run === null) {
            $last = is_array($state['last'] ?? null) ? $state['last'] : null;

            if ($last !== null && $now - (int) $last['scanned_at'] < self::RESCAN_AFTER) {
                return $state;
            }

            $run = ['queue' => array_map(fn (string $root) => [$root, $root], $roots), 'scanned' => 0, 'total' => 0, 'files' => []];
        }

        $entries = 0;

        while ($run['queue'] !== [] && $entries < $maxEntries && microtime(true) < $deadline) {
            [$root, $dir] = array_shift($run['queue']);
            // A missing or unreadable folder is skipped.
            $names = is_dir($dir) && is_readable($dir) ? @scandir($dir) : false;

            if (! is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $entries++;
                $path = $dir.'/'.$name;

                if (is_dir($path)) {
                    // A linked folder may lead anywhere, even back up.
                    if (! is_link($path)) {
                        $run['queue'][] = [$root, $path];
                    }

                    continue;
                }

                $kind = self::kind($name, $path);

                if ($kind === null) {
                    continue;
                }

                $run['total']++;

                if (count($run['files']) < self::MAX) {
                    $size = @filesize($path);
                    $modified = @filemtime($path);
                    $run['files'][] = [
                        'path' => self::relative($path, $root, $base),
                        'kind' => $kind,
                        'size' => is_int($size) ? $size : 0,
                        'modified_at' => is_int($modified) ? date(DATE_ATOM, $modified) : null,
                    ];
                }
            }
        }

        $run['scanned'] += $entries;

        if ($run['queue'] !== []) {
            return [...$state, 'run' => $run];
        }

        usort($run['files'], fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return ['last' => ['scanned_at' => $now, 'scanned' => $run['scanned'], 'total' => $run['total'], 'files' => $run['files']]];
    }

    /**
     * The report's `suspicious_files` from the cached state; null until a
     * scan was complete.
     *
     * @param  array<string, mixed>  $state
     * @return array{scanned_at: string, scanned: int, total: int, files: list<array{path: string, kind: string, size: int, modified_at: string|null}>}|null
     */
    public static function forReport(array $state): ?array
    {
        $last = $state['last'] ?? null;

        if (! is_array($last)) {
            return null;
        }

        return [
            'scanned_at' => date(DATE_ATOM, (int) $last['scanned_at']),
            'scanned' => (int) $last['scanned'],
            'total' => (int) $last['total'],
            'files' => array_values((array) $last['files']),
        ];
    }

    /**
     * Why a file is suspicious: `php` for a PHP extension, `double_extension`
     * for one hidden before the last, `htaccess` for an .htaccess that lets
     * PHP run; null if it isn't.
     */
    public static function kind(string $name, string $path): ?string
    {
        $lower = strtolower($name);

        if ($lower === '.htaccess') {
            return self::htaccessRunsPhp($path) ? 'htaccess' : null;
        }

        $parts = explode('.', $lower);

        if (count($parts) < 2) {
            return null;
        }

        if (in_array(end($parts), self::EXTENSIONS, true)) {
            return $lower === 'index.php' && self::placeholder($path) ? null : 'php';
        }

        return array_intersect(array_slice($parts, 1, -1), self::EXTENSIONS) !== [] ? 'double_extension' : null;
    }

    /** An index.php that does nothing: empty, or only `<?php` and comments. */
    private static function placeholder(string $path): bool
    {
        $size = @filesize($path);
        $code = is_int($size) && $size <= self::MAX_PLACEHOLDER ? @file_get_contents($path) : false;

        if (! is_string($code)) {
            return false;
        }

        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
        $code = str_ireplace(['<?php', '?>'], '', $code);

        return trim((string) preg_replace('/(\/\/|#)[^\n]*/', '', $code)) === '';
    }

    /**
     * Whether an .htaccess hands files to PHP (AddType, AddHandler,
     * SetHandler or ForceType with a PHP type or handler) or switches the PHP
     * engine on. Rules that keep PHP from running, such as
     * `AddType text/plain .php` or `php_flag engine off`, don't count.
     */
    private static function htaccessRunsPhp(string $path): bool
    {
        $content = @file_get_contents($path, false, null, 0, self::MAX_HTACCESS);

        if (! is_string($content)) {
            return false;
        }

        return preg_match('/^\s*(?:AddType|AddHandler|SetHandler|ForceType)\s+["\']?[^\s"\']*php/im', $content) === 1
            || preg_match('/^\s*php_(?:admin_)?flag\s+engine\s+(?:on|1|true)\b/im', $content) === 1;
    }

    /**
     * The path relative to the app, or for a folder outside it, relative to
     * the folder's parent.
     */
    private static function relative(string $path, string $root, string $base): string
    {
        $base = rtrim($base, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($root).substr($path, strlen($root));
    }

    private function cache(): Repository
    {
        return $this->app['cache']->store();
    }
}
