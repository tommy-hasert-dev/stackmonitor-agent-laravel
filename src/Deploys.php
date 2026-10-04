<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;

/**
 * The last deploy of the app (#150), sent in `extra.deploy`, so the
 * dashboard can mark it in its charts. The agent looks, in this order, at:
 *
 * - a file the deploy script writes (`.stackmonitor-deploy` in the app,
 *   another path with STACKMONITOR_AGENT_DEPLOY_FILE): its modification time
 *   is the deploy, its first line, if any, the revision (a commit hash, a
 *   version). Works without Git on the server, e.g. for rsync deploys.
 * - the Git checkout: the commit HEAD points to, and when its branch moved
 *   there.
 * - the cached configuration (`php artisan config:cache`, part of most
 *   deploy scripts): when it was written, without a revision.
 *
 * Null when none of them is there.
 */
final class Deploys
{
    public const FILE = '.stackmonitor-deploy';

    /** The longest revision sent; a full SHA-1 commit hash fits. */
    public const MAX_REVISION = 40;

    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array{at: string, revision: string|null, source: string}|null
     */
    public function report(): ?array
    {
        return $this->fromFile() ?? $this->fromGit() ?? $this->fromConfigCache();
    }

    /** @return array{at: string, revision: string|null, source: string}|null */
    private function fromFile(): ?array
    {
        $file = config('stackmonitor-agent.deploy_file');
        $file = is_string($file) && $file !== '' ? $file : self::FILE;
        $path = str_starts_with($file, '/') ? $file : $this->base().'/'.$file;
        $modified = is_file($path) ? @filemtime($path) : false;

        if (! is_int($modified)) {
            return null;
        }

        $handle = @fopen($path, 'r');
        $line = $handle === false ? false : fgets($handle, 1024);

        if ($handle !== false) {
            fclose($handle);
        }

        $revision = is_string($line) ? substr(trim($line), 0, self::MAX_REVISION) : '';

        return self::deploy($modified, $revision === '' ? null : $revision, 'file');
    }

    /**
     * The commit of HEAD, dated by the file that holds it: the branch's ref,
     * the packed refs, or HEAD itself when detached. A worktree's `.git` file
     * leads to its Git directory, its `commondir` to the shared refs.
     *
     * @return array{at: string, revision: string|null, source: string}|null
     */
    private function fromGit(): ?array
    {
        $git = $this->base().'/.git';

        if (is_file($git)) {
            $pointer = (string) @file_get_contents($git, false, null, 0, 1024);

            if (preg_match('/^gitdir:\s*(\S.*)$/m', $pointer, $m) !== 1) {
                return null;
            }

            $git = str_starts_with(trim($m[1]), '/') ? trim($m[1]) : $this->base().'/'.trim($m[1]);
        }

        $head = is_file($git.'/HEAD') ? trim((string) @file_get_contents($git.'/HEAD', false, null, 0, 1024)) : '';

        if ($head === '') {
            return null;
        }

        if (! str_starts_with($head, 'ref:')) {
            return self::isHash($head) ? self::deploy(@filemtime($git.'/HEAD'), substr($head, 0, self::MAX_REVISION), 'git') : null;
        }

        $ref = trim(substr($head, 4));
        $common = is_file($git.'/commondir') ? trim((string) @file_get_contents($git.'/commondir')) : '';
        $dirs = $common === '' ? [$git] : [$git, str_starts_with($common, '/') ? $common : $git.'/'.$common];

        foreach ($dirs as $dir) {
            $hash = is_file($dir.'/'.$ref) ? trim((string) @file_get_contents($dir.'/'.$ref, false, null, 0, 1024)) : '';

            if (self::isHash($hash)) {
                return self::deploy(@filemtime($dir.'/'.$ref), substr($hash, 0, self::MAX_REVISION), 'git');
            }
        }

        foreach ($dirs as $dir) {
            $packed = is_file($dir.'/packed-refs') ? (string) @file_get_contents($dir.'/packed-refs') : '';

            if (preg_match('/^([0-9a-f]{40,64}) '.preg_quote($ref, '/').'$/m', $packed, $m) === 1) {
                return self::deploy(@filemtime($dir.'/packed-refs'), substr($m[1], 0, self::MAX_REVISION), 'git');
            }
        }

        return null;
    }

    /** @return array{at: string, revision: string|null, source: string}|null */
    private function fromConfigCache(): ?array
    {
        $path = $this->app->getCachedConfigPath();

        return is_file($path) ? self::deploy(@filemtime($path), null, 'config_cache') : null;
    }

    /** @return array{at: string, revision: string|null, source: string}|null */
    private static function deploy(int|false $modified, ?string $revision, string $source): ?array
    {
        return is_int($modified) ? ['at' => date(DATE_ATOM, $modified), 'revision' => $revision, 'source' => $source] : null;
    }

    private static function isHash(string $value): bool
    {
        return preg_match('/^[0-9a-f]{40,64}$/', $value) === 1;
    }

    private function base(): string
    {
        return rtrim($this->basePath ?? $this->app->basePath(), '/');
    }
}
