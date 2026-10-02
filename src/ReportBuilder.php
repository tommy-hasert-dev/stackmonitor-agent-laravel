<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use StackMonitor\Agent\ErrorLog\ErrorLog;

final class ReportBuilder
{
    public const AGENT_VERSION = '1.4.0';

    /** What composer.lock records for a package installed from packagist.org. */
    private const PACKAGIST_NOTIFICATION_URL = 'https://packagist.org/downloads/';

    public function __construct(
        private readonly Application $app,
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'schema_version' => 1,
            'agent_version' => self::AGENT_VERSION,
            'platform' => 'laravel',
            'core' => ['version' => $this->app->version(), 'update_available' => null],
            // PHP_VERSION can carry a distro suffix (e.g. Debian/Ubuntu package builds);
            // compose the plain major.minor.release string instead (schema maxLength 20).
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION,
            'packages' => $this->packages(),
            'flags' => ['debug' => (bool) config('app.debug'), 'environment' => (string) $this->app->environment()],
            'extra' => (object) $this->app->make(OperationalData::class)->report(),
            'error_log' => $this->errorLog(),
        ];
    }

    /**
     * Errors of the last 24 hours (#81); the messages can be switched off with
     * STACKMONITOR_AGENT_LOG_MESSAGES=false, the counts are always sent.
     *
     * @return array<string, mixed>
     */
    private function errorLog(): array
    {
        $channels = config('logging.channels');

        return (new ErrorLog(
            is_array($channels) ? $channels : [],
            (string) config('logging.default'),
            date_default_timezone_get(),
            (bool) config('stackmonitor-agent.log_messages', true),
        ))->report();
    }

    /**
     * `listed` tells whether the package came from packagist.org, so the
     * dashboard links only public packages there, not private ones from a
     * VCS, path or Private Packagist repository.
     *
     * @return list<array{type: string, name: string, version: string, update_available: null, direct: bool, listed: bool}>
     */
    private function packages(): array
    {
        $base = $this->basePath ?? $this->app->basePath();
        $lock = $this->readJson($base.'/composer.lock');
        $direct = array_keys((array) ($this->readJson($base.'/composer.json')['require'] ?? []));
        $packages = [];

        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (! is_array($package) || ! isset($package['name'], $package['version'])) {
                continue;
            }

            $packages[] = [
                'type' => 'composer',
                'name' => (string) $package['name'],
                'version' => (string) $package['version'],
                'update_available' => null,
                'direct' => in_array($package['name'], $direct, true),
                'listed' => ($package['notification-url'] ?? null) === self::PACKAGIST_NOTIFICATION_URL,
            ];
        }

        return $packages;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }
}
