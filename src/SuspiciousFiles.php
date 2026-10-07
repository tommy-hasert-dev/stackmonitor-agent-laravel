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
 * With STACKMONITOR_AGENT_FILE_CONTENTS on, the dashboard may ask for the
 * start of one file of the last complete scan by the hash of its path
 * (content()); no other file, and none that changed or became a link since.
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

    /** The most of a file sent to the dashboard on request. */
    public const MAX_CONTENT_BYTES = 65536;

    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * Advances the scan and returns the last complete result, null before
     * the first one.
     *
     * @return array{scanned_at: string, scanned: int, total: int, contents: bool, files: list<array{path: string, kind: string, size: int, modified_at: string|null}>}|null
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

        return self::forReport($state, filter_var(config('stackmonitor-agent.file_contents'), FILTER_VALIDATE_BOOL));
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
                        // Only for content(): stays in the cache, never in the report.
                        'abs' => $path,
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
     * scan was complete. `contents` says whether the dashboard may ask for
     * the start of a file.
     *
     * @param  array<string, mixed>  $state
     * @return array{scanned_at: string, scanned: int, total: int, contents: bool, files: list<array{path: string, kind: string, size: int, modified_at: string|null}>}|null
     */
    public static function forReport(array $state, bool $contents = false): ?array
    {
        $last = $state['last'] ?? null;

        if (! is_array($last)) {
            return null;
        }

        return [
            'scanned_at' => date(DATE_ATOM, (int) $last['scanned_at']),
            'scanned' => (int) $last['scanned'],
            'total' => (int) $last['total'],
            'contents' => $contents,
            'files' => array_values(array_map(
                fn (array $file) => array_diff_key($file, ['abs' => true]),
                (array) $last['files'],
            )),
        ];
    }

    /**
     * The start of a file of the last complete scan, by the hash of its
     * reported path; null for any other file. Whether the app allows it is
     * up to the caller.
     *
     * @return array{path_hash: string, size: int, sha256: string, truncated: bool, content: string}|null
     */
    public function content(string $pathHash): ?array
    {
        $state = $this->cache()->get(self::CACHE_KEY);

        return self::read(is_array($state) ? $state : [], $pathHash, $this->roots(), self::MAX_CONTENT_BYTES);
    }

    /**
     * Reads at most $maxBytes of the file whose path hashes to $pathHash, if
     * it is in the last complete scan, still no link, inside one of $roots
     * and of the size and date the scan saw. The content goes out as base64.
     *
     * @param  array<string, mixed>  $state  as cached
     * @param  list<string>  $roots
     * @return array{path_hash: string, size: int, sha256: string, truncated: bool, content: string}|null
     */
    public static function read(array $state, string $pathHash, array $roots, int $maxBytes): ?array
    {
        $file = null;

        foreach ((array) ($state['last']['files'] ?? []) as $candidate) {
            if (is_array($candidate) && is_string($candidate['path'] ?? null) && hash_equals(hash('sha256', $candidate['path']), $pathHash)) {
                $file = $candidate;
                break;
            }
        }

        $path = $file['abs'] ?? null;

        if ($file === null || ! is_string($path) || is_link($path) || ! is_file($path) || ! is_readable($path) || ! self::inside($path, $roots)) {
            return null;
        }

        clearstatcache(true, $path);
        $size = @filesize($path);
        $modified = @filemtime($path);

        if ($size !== ($file['size'] ?? null) || (is_int($modified) ? date(DATE_ATOM, $modified) : null) !== ($file['modified_at'] ?? null)) {
            return null;
        }

        $content = @file_get_contents($path, false, null, 0, $maxBytes);
        $sha256 = @hash_file('sha256', $path);

        if (! is_string($content) || ! is_string($sha256)) {
            return null;
        }

        return [
            'path_hash' => $pathHash,
            'size' => $size,
            'sha256' => $sha256,
            'truncated' => $size > $maxBytes,
            'content' => base64_encode($content),
        ];
    }

    /**
     * Whether the file, all links resolved, lies inside one of the roots: a
     * folder swapped for a link since the scan leads elsewhere.
     *
     * @param  list<string>  $roots
     */
    private static function inside(string $path, array $roots): bool
    {
        $real = realpath($path);

        foreach ($roots as $root) {
            $rootReal = realpath($root);

            if (is_string($real) && is_string($rootReal) && str_starts_with($real, rtrim($rootReal, '/').'/')) {
                return true;
            }
        }

        return false;
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
