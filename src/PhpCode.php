<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use ZipArchive;

/**
 * The PHP code the dashboard can't download itself (#159), sent in
 * `extra.php_code`: the app's own code (`app/`) and every package of
 * composer.lock that isn't listed on packagist.org (a VCS, path or Private
 * Packagist repository), with how many PHP files it has, their size and a
 * fingerprint. The dashboard scans the code with PHPCompatibility to tell
 * whether the next PHP version breaks it; the public packages it fetches
 * from Packagist, and from the fingerprint it knows when one of the others
 * changed and has to be scanned again. Development packages stay out, they
 * don't run on the server.
 *
 * With STACKMONITOR_AGENT_FILE_CONTENTS on, the dashboard may ask for the
 * PHP files of one of these packages as a ZIP by its `id` (archive()); no
 * other file, and nothing outside the package's folder.
 *
 * The fingerprint only reads the file list with sizes and dates, never the
 * contents, within MAX_SECONDS per report: the last result of every package
 * is kept in the app's cache and reused for CACHE_SECONDS; the ones looked
 * at longest ago come first, and a package the time isn't left for keeps
 * its last result, or is left out until it had its turn. One that didn't
 * get through in time goes to the back, so it can't hold up the others.
 */
final class PhpCode
{
    public const CACHE_KEY = 'stackmonitor-agent:php-code';

    /** The most packages the report schema takes; the app comes first. */
    public const MAX_PACKAGES = 100;

    /** The most PHP files of one package with a fingerprint and in a ZIP. */
    public const MAX_FILES = 5000;

    /** Seconds spent per report at most, checked between directories. */
    public const MAX_SECONDS = 2.0;

    /** Seconds a package's fingerprint is reused before it is measured again. */
    public const CACHE_SECONDS = 600;

    /** Folders without PHP that run on the server, not walked through. */
    private const SKIPPED_FOLDERS = ['node_modules', '.git'];

    /** The largest ZIP sent to the dashboard. */
    public const MAX_ARCHIVE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array{contents: bool, packages: list<array{type: string, name: string, id: string, fingerprint: string|null, files: int, bytes: int}>}
     */
    public function report(): array
    {
        $cached = $this->cache()->get(self::CACHE_KEY);
        [$state, $packages] = self::measure(
            is_array($cached) ? $cached : [],
            self::packages($this->basePath ?? $this->app->basePath()),
            now()->getTimestamp(),
            self::MAX_FILES,
            microtime(true) + self::MAX_SECONDS,
        );

        $this->cache()->forever(self::CACHE_KEY, $state);

        return [
            'contents' => filter_var(config('stackmonitor-agent.file_contents'), FILTER_VALIDATE_BOOL),
            'packages' => $packages,
        ];
    }

    /**
     * The app's own code and the packages of composer.lock that aren't on
     * packagist.org, with the folder they live in; one whose folder is
     * missing is left out. The `listed` decision is the report's.
     *
     * @return list<array{type: string, name: string, id: string, root: string}>
     */
    public static function packages(string $base): array
    {
        $base = rtrim($base, '/');
        $packages = [];

        if (is_dir($base.'/app')) {
            $packages[] = self::package('app', 'app', $base.'/app');
        }

        $lock = is_file($base.'/composer.lock') ? json_decode((string) file_get_contents($base.'/composer.lock'), true) : null;

        foreach ((array) (is_array($lock) ? ($lock['packages'] ?? []) : []) as $package) {
            $name = is_array($package) ? ($package['name'] ?? null) : null;

            // A name that isn't vendor/package could lead out of vendor/.
            if (! is_string($name) || preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $name) !== 1 || str_contains($name, '..') || ReportBuilder::listed($package)) {
                continue;
            }

            if (is_dir($base.'/vendor/'.$name)) {
                $packages[] = self::package('composer', $name, $base.'/vendor/'.$name);
            }
        }

        return array_slice($packages, 0, self::MAX_PACKAGES);
    }

    /**
     * Measures the packages looked at longest ago first until the deadline,
     * skipping those measured within CACHE_SECONDS and keeping the last
     * result of the others; returns the new state and the report's packages
     * in their order, without the ones never measured. A package the
     * deadline cut off is marked `tried`, which puts it at the back.
     *
     * @param  array<string, mixed>  $state  as cached: id => result with `at`, or only `tried`
     * @param  list<array{type: string, name: string, id: string, root: string}>  $packages
     * @return array{0: array<string, array{fingerprint?: string|null, files?: int, bytes?: int, at?: int, tried?: int}>, 1: list<array{type: string, name: string, id: string, fingerprint: string|null, files: int, bytes: int}>}
     */
    public static function measure(array $state, array $packages, int $now, int $maxFiles, float $deadline): array
    {
        $next = [];
        $measured = fn (array $entry) => array_key_exists('fingerprint', $entry) && isset($entry['files'], $entry['bytes'], $entry['at']);

        foreach ($packages as $package) {
            $last = $state[$package['id']] ?? null;

            if (is_array($last) && ($measured($last) || isset($last['tried']))) {
                $next[$package['id']] = $last;
            }
        }

        $lookedAt = fn (array $package) => max((int) ($next[$package['id']]['at'] ?? 0), (int) ($next[$package['id']]['tried'] ?? 0));
        $order = $packages;
        usort($order, fn (array $a, array $b) => $lookedAt($a) <=> $lookedAt($b));

        foreach ($order as $package) {
            $last = $next[$package['id']] ?? null;

            if (is_array($last) && $measured($last) && $now - (int) $last['at'] < self::CACHE_SECONDS) {
                continue;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            $files = self::files($package['root'], $maxFiles, $deadline);

            if ($files === null) {
                $next[$package['id']] = [...(is_array($last) ? $last : []), 'tried' => $now];

                continue;
            }

            $next[$package['id']] = [
                'fingerprint' => $files['entries'] === null ? null : self::fingerprint($files['entries']),
                'files' => $files['files'],
                'bytes' => $files['bytes'],
                'at' => $now,
            ];
        }

        $report = [];

        foreach ($packages as $package) {
            $result = $next[$package['id']] ?? null;

            if (is_array($result) && $measured($result)) {
                $report[] = [
                    'type' => $package['type'],
                    'name' => $package['name'],
                    'id' => $package['id'],
                    'fingerprint' => is_string($result['fingerprint']) ? $result['fingerprint'] : null,
                    'files' => (int) $result['files'],
                    'bytes' => (int) $result['bytes'],
                ];
            }
        }

        return [$next, $report];
    }

    /**
     * Every PHP file below the root, the root itself followed if it is a
     * link (a path repository's package in vendor/ is one), but no linked
     * folder or file inside it: those may lead anywhere, nor SKIPPED_FOLDERS.
     * Past $maxFiles it stops: `entries` is null and `files` and `bytes`
     * count what it saw. The whole result is null when the deadline passed
     * before the folder was through.
     *
     * @return array{files: int, bytes: int, entries: list<array{path: string, abs: string, size: int, mtime: int}>|null}|null
     */
    public static function files(string $root, int $maxFiles, float $deadline): ?array
    {
        $root = realpath($root);

        if ($root === false || ! is_dir($root)) {
            return ['files' => 0, 'bytes' => 0, 'entries' => []];
        }

        $queue = [$root];
        $files = 0;
        $bytes = 0;
        $entries = [];

        while ($queue !== []) {
            if (microtime(true) >= $deadline) {
                return null;
            }

            $dir = array_shift($queue);
            // A missing or unreadable folder is skipped.
            $names = is_readable($dir) ? @scandir($dir) : false;

            if (! is_array($names)) {
                continue;
            }

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $dir.'/'.$name;

                if (is_link($path)) {
                    continue;
                }

                if (is_dir($path)) {
                    if (! in_array($name, self::SKIPPED_FOLDERS, true)) {
                        $queue[] = $path;
                    }

                    continue;
                }

                if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'php' || ! is_file($path)) {
                    continue;
                }

                $size = @filesize($path);
                $mtime = @filemtime($path);
                $files++;
                $bytes += is_int($size) ? $size : 0;

                if ($files > $maxFiles) {
                    return ['files' => $files, 'bytes' => $bytes, 'entries' => null];
                }

                if ($entries !== null) {
                    $entries[] = [
                        'path' => substr($path, strlen($root) + 1),
                        'abs' => $path,
                        'size' => is_int($size) ? $size : 0,
                        'mtime' => is_int($mtime) ? $mtime : 0,
                    ];
                }
            }
        }

        if ($entries !== null) {
            usort($entries, fn (array $a, array $b) => strcmp($a['path'], $b['path']));
        }

        return ['files' => $files, 'bytes' => $bytes, 'entries' => $entries];
    }

    /**
     * SHA-256 over one line `path\tsize\tmtime\n` per file, sorted by path:
     * changes with every file added, removed, renamed or saved, without
     * reading one.
     *
     * @param  list<array{path: string, size: int, mtime: int}>  $entries  sorted by path
     */
    public static function fingerprint(array $entries): string
    {
        $hash = hash_init('sha256');

        foreach ($entries as $entry) {
            hash_update($hash, $entry['path']."\t".$entry['size']."\t".$entry['mtime']."\n");
        }

        return hash_final($hash);
    }

    /**
     * The PHP files of the package with this id as a ZIP, or why there is
     * none; null for an id that isn't one of the reported packages. Whether
     * the app allows it is up to the caller.
     *
     * @return array{id: string, fingerprint: string, files: int, bytes: int, sha256: string, archive: string}|array{id: string, error: string}|null
     */
    public function archive(string $id): ?array
    {
        foreach (self::packages($this->basePath ?? $this->app->basePath()) as $package) {
            if (hash_equals($package['id'], $id)) {
                return self::zip($package['id'], $package['root'], self::MAX_FILES, self::MAX_ARCHIVE_BYTES, class_exists(ZipArchive::class));
            }
        }

        return null;
    }

    /**
     * Packs every PHP file below the root at its path relative to it, in a
     * temporary file that is removed afterwards. The ZIP goes out as base64,
     * with its SHA-256 and the fingerprint of the files in it, so the
     * dashboard knows which state it scanned.
     *
     * @return array{id: string, fingerprint: string, files: int, bytes: int, sha256: string, archive: string}|array{id: string, error: string}
     */
    public static function zip(string $id, string $root, int $maxFiles, int $maxBytes, bool $canZip): array
    {
        if (! $canZip) {
            return ['id' => $id, 'error' => 'no_zip'];
        }

        $files = self::files($root, $maxFiles, INF);

        if ($files === null || $files['entries'] === null) {
            return ['id' => $id, 'error' => 'too_many_files'];
        }

        $temp = tempnam(sys_get_temp_dir(), 'stackmonitor-php-');
        $zip = new ZipArchive;

        if ($temp === false || $zip->open($temp, ZipArchive::OVERWRITE) !== true) {
            return ['id' => $id, 'error' => 'no_zip'];
        }

        try {
            foreach ($files['entries'] as $entry) {
                $zip->addFile($entry['abs'], $entry['path']);
            }

            // close() reads the files; one that went away since fails it.
            if (! $zip->close() && $files['entries'] !== []) {
                return ['id' => $id, 'error' => 'no_zip'];
            }

            clearstatcache(true, $temp);

            if ($files['entries'] !== [] && @filesize($temp) > $maxBytes) {
                return ['id' => $id, 'error' => 'too_large'];
            }

            // ZipArchive doesn't write an archive without entries at all.
            $archive = $files['entries'] === [] ? self::emptyZip() : @file_get_contents($temp);

            if (! is_string($archive)) {
                return ['id' => $id, 'error' => 'no_zip'];
            }

            return [
                'id' => $id,
                'fingerprint' => self::fingerprint($files['entries']),
                'files' => $files['files'],
                'bytes' => $files['bytes'],
                'sha256' => hash('sha256', $archive),
                'archive' => base64_encode($archive),
            ];
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array{type: string, name: string, id: string, root: string}
     */
    private static function package(string $type, string $name, string $root): array
    {
        return ['type' => $type, 'name' => $name, 'id' => hash('sha256', $type.':'.$name), 'root' => $root];
    }

    /** A ZIP without entries: just the end of central directory record. */
    private static function emptyZip(): string
    {
        return "PK\x05\x06".str_repeat("\0", 18);
    }

    private function cache(): Repository
    {
        return $this->app['cache']->store();
    }
}
