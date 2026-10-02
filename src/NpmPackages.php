<?php

namespace StackMonitor\Agent;

/**
 * The JavaScript packages of the app as its lockfile records them (#141), so
 * the dashboard can check them for known vulnerabilities: every installed
 * package, transitive ones too, with whether it is only needed for
 * development. Read from the file alone, without running npm.
 */
final class NpmPackages
{
    /** Checked in this order; the first one found is read. */
    private const LOCKFILES = ['package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'];

    /** npm's own limit for package names. */
    private const MAX_NAME = 214;

    private const MAX_VERSION = 100;

    public function __construct(
        private readonly string $basePath,
        private readonly int $limit = 5000,
    ) {}

    /**
     * Null when the app has no package.json; `lockfile` is null when it has
     * one but no lockfile next to it, as when the assets are built in CI.
     *
     * @return array{lockfile: string|null, truncated: bool, packages: list<array{name: string, version: string, dev: bool}>}|null
     */
    public function report(): ?array
    {
        $manifest = $this->readJson('package.json');

        if ($manifest === null) {
            return null;
        }

        foreach (self::LOCKFILES as $lockfile) {
            if (is_file($this->basePath.'/'.$lockfile)) {
                return $this->packages($lockfile, $this->directDev($manifest));
            }
        }

        return ['lockfile' => null, 'truncated' => false, 'packages' => []];
    }

    /**
     * @param  array<string, true>  $directDev
     * @return array{lockfile: string, truncated: bool, packages: list<array{name: string, version: string, dev: bool}>}
     */
    private function packages(string $lockfile, array $directDev): array
    {
        $entries = match ($lockfile) {
            'package-lock.json' => $this->fromPackageLock(),
            'pnpm-lock.yaml' => $this->fromPnpmLock($directDev),
            default => $this->fromYarnLock($directDev),
        };

        // One entry per name and version; installed for production anywhere,
        // it counts as production.
        $packages = [];

        foreach ($entries as [$name, $version, $dev]) {
            if ($name === '' || strlen($name) > self::MAX_NAME || $version === '' || strlen($version) > self::MAX_VERSION) {
                continue;
            }

            $key = $name."\0".$version;
            $packages[$key] = ['name' => $name, 'version' => $version, 'dev' => $dev && ($packages[$key]['dev'] ?? true)];
        }

        ksort($packages, SORT_STRING);

        return [
            'lockfile' => $lockfile,
            'truncated' => count($packages) > $this->limit,
            'packages' => array_slice(array_values($packages), 0, $this->limit),
        ];
    }

    /**
     * Version 2 and 3 list every installed package under `packages`, keyed by
     * its path in node_modules; version 1 nests them under `dependencies`.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function fromPackageLock(): array
    {
        $lock = $this->readJson('package-lock.json') ?? [];

        if (is_array($lock['packages'] ?? null)) {
            $entries = [];

            foreach ($lock['packages'] as $path => $package) {
                $at = strrpos((string) $path, 'node_modules/');

                // The root and workspace folders aren't installed packages, links point at them.
                if ($at === false || ! is_array($package) || ($package['link'] ?? false) === true || ! is_string($package['version'] ?? null)) {
                    continue;
                }

                $dev = ($package['dev'] ?? false) === true || ($package['devOptional'] ?? false) === true;
                $entries[] = [substr((string) $path, $at + strlen('node_modules/')), $package['version'], $dev];
            }

            return $entries;
        }

        return $this->fromV1Dependencies(is_array($lock['dependencies'] ?? null) ? $lock['dependencies'] : []);
    }

    /**
     * @param  array<mixed>  $dependencies
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function fromV1Dependencies(array $dependencies): array
    {
        $entries = [];

        foreach ($dependencies as $name => $package) {
            if (! is_array($package) || ! is_string($package['version'] ?? null)) {
                continue;
            }

            $entries[] = [(string) $name, $package['version'], ($package['dev'] ?? false) === true];

            if (is_array($package['dependencies'] ?? null)) {
                array_push($entries, ...$this->fromV1Dependencies($package['dependencies']));
            }
        }

        return $entries;
    }

    /**
     * Reads the keys of the `packages` section line by line, which needs no
     * YAML parser: `/name@1.0.0` (v6), `name@1.0.0` (v9) or `/name/1.0.0` (v5),
     * each with optional peer suffixes. Up to v8 an entry says `dev: true`;
     * v9 no longer does, then only direct devDependencies count as such.
     *
     * @param  array<string, true>  $directDev
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function fromPnpmLock(array $directDev): array
    {
        $entries = [];
        $inPackages = false;
        $current = null;

        foreach ($this->lines('pnpm-lock.yaml') as $line) {
            if ($line === '' || trim($line) === '') {
                continue;
            }

            if ($line[0] !== ' ') {
                $inPackages = rtrim($line) === 'packages:';

                continue;
            }

            if (! $inPackages) {
                continue;
            }

            if (preg_match('/^  (\S.*):\s*$/', $line, $match) === 1) {
                $current = $this->pnpmKey(trim($match[1], '\'"'));

                if ($current !== null) {
                    $entries[] = [$current[0], $current[1], isset($directDev[$current[0]])];
                    $current = array_key_last($entries);
                }

                continue;
            }

            if ($current !== null && preg_match('/^    dev:\s*(true|false)\s*$/', $line, $match) === 1) {
                $entries[$current][2] = $match[1] === 'true';
            }
        }

        return $entries;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function pnpmKey(string $key): ?array
    {
        $key = ltrim($key, '/');
        $key = (string) preg_replace('/\(.*$/', '', $key);
        $at = strrpos($key, '@');

        if ($at !== false && $at > 0) {
            return [substr($key, 0, $at), substr($key, $at + 1)];
        }

        // v5: name/version, the version possibly with a peer suffix after "_".
        $slash = strrpos($key, '/');

        if ($slash === false || $slash === 0) {
            return null;
        }

        return [substr($key, 0, $slash), explode('_', substr($key, $slash + 1))[0]];
    }

    /**
     * Yarn 1 and Berry: an unindented header with the requested ranges, then
     * the resolved `version` indented below. yarn.lock doesn't tell dev from
     * production, so only direct devDependencies count as dev.
     *
     * @param  array<string, true>  $directDev
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function fromYarnLock(array $directDev): array
    {
        $entries = [];
        $name = null;

        foreach ($this->lines('yarn.lock') as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if ($line[0] !== ' ') {
                $name = $this->yarnName(rtrim($line));

                continue;
            }

            if ($name !== null && preg_match('/^  version:? "?([^"\s]+)"?\s*$/', $line, $match) === 1) {
                $entries[] = [$name, $match[1], isset($directDev[$name])];
                $name = null;
            }
        }

        return $entries;
    }

    private function yarnName(string $header): ?string
    {
        if (! str_ends_with($header, ':') || str_starts_with($header, '__metadata')) {
            return null;
        }

        $spec = trim(explode(',', substr($header, 0, -1))[0], ' "\'');
        $at = strpos($spec, '@', 1);

        if ($at === false) {
            return null;
        }

        // The app itself and local folders aren't packages from the registry.
        $range = substr($spec, $at + 1);

        foreach (['workspace:', 'link:', 'portal:', 'file:'] as $protocol) {
            if (str_starts_with($range, $protocol)) {
                return null;
            }
        }

        return substr($spec, 0, $at);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, true>
     */
    private function directDev(array $manifest): array
    {
        $dev = is_array($manifest['devDependencies'] ?? null) ? $manifest['devDependencies'] : [];
        $production = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];

        return array_fill_keys(array_map('strval', array_keys(array_diff_key($dev, $production))), true);
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        $content = @file_get_contents($this->basePath.'/'.$file);

        return $content === false ? [] : (preg_split('/\r?\n/', $content) ?: []);
    }

    /**
     * @return array<string, mixed>|null null when the file is missing; unreadable JSON is empty
     */
    private function readJson(string $file): ?array
    {
        $path = $this->basePath.'/'.$file;

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
