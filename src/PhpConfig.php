<?php

namespace StackMonitor\Agent;

/**
 * The PHP settings behind many problems of a site (#140), sent in the
 * report's `extra.php_config`: OPcache, the limits, display_errors and the
 * loaded extensions. The dashboard asks over HTTP, so these are the web
 * server's values, not the command line's that cron and queue workers use.
 */
final class PhpConfig
{
    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $opcache = extension_loaded('Zend OPcache') && function_exists('opcache_get_status')
            ? @opcache_get_status(false)
            : null;

        return self::from(
            PHP_SAPI,
            fn (string $name) => ini_get($name),
            $this->masterDisplayErrors(),
            extension_loaded('Zend OPcache'),
            $opcache,
            [...get_loaded_extensions(), ...get_loaded_extensions(true)],
        );
    }

    /**
     * @param  callable(string): (string|false)  $ini
     * @param  mixed  $opcache  what opcache_get_status(false) returned, false when restricted
     * @param  list<string>  $extensions
     * @return array<string, mixed>
     */
    public static function from(string $sapi, callable $ini, ?string $displayErrors, bool $opcacheLoaded, mixed $opcache, array $extensions): array
    {
        $string = function (string $name) use ($ini): ?string {
            $value = $ini($name);

            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 50) : null;
        };
        $int = fn (string $name) => is_numeric($ini($name)) ? (int) $ini($name) : null;

        return [
            'sapi' => mb_substr($sapi, 0, 50),
            'opcache' => $opcacheLoaded ? self::opcache($opcache, $string('opcache.enable')) : null,
            'memory_limit' => $string('memory_limit'),
            'max_execution_time' => $int('max_execution_time'),
            'upload_max_filesize' => $string('upload_max_filesize'),
            'post_max_size' => $string('post_max_size'),
            'display_errors' => $displayErrors === null ? null : mb_substr($displayErrors, 0, 50),
            'error_reporting' => $int('error_reporting'),
            'timezone' => $string('date.timezone'),
            'extensions' => array_slice(array_values(array_unique(array_map('strtolower', $extensions))), 0, 300),
        ];
    }

    /**
     * Without opcache_get_status() (restricted by opcache.restrict_api) only
     * the setting tells whether it's on; the fill level is unknown then.
     *
     * @return array{enabled: bool, memory_used: int|null, memory_total: int|null, keys_used: int|null, keys_max: int|null, full: bool|null}
     */
    private static function opcache(mixed $status, ?string $enable): array
    {
        if (! is_array($status)) {
            return [
                'enabled' => in_array(strtolower((string) $enable), ['1', 'on', 'yes', 'true'], true),
                'memory_used' => null, 'memory_total' => null, 'keys_used' => null, 'keys_max' => null, 'full' => null,
            ];
        }

        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $used = isset($memory['used_memory'], $memory['wasted_memory']) ? (int) $memory['used_memory'] + (int) $memory['wasted_memory'] : null;

        return [
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            // Wasted memory stays taken until OPcache restarts.
            'memory_used' => $used,
            'memory_total' => $used !== null && isset($memory['free_memory']) ? $used + (int) $memory['free_memory'] : null,
            'keys_used' => isset($statistics['num_cached_keys']) ? (int) $statistics['num_cached_keys'] : null,
            'keys_max' => isset($statistics['max_cached_keys']) ? (int) $statistics['max_cached_keys'] : null,
            'full' => isset($status['cache_full']) ? (bool) $status['cache_full'] : null,
        ];
    }

    /**
     * Laravel switches display_errors off while it boots; the value from the
     * PHP configuration is what shows an error before that.
     */
    private function masterDisplayErrors(): ?string
    {
        $all = function_exists('ini_get_all') ? @ini_get_all(null, true) : false;
        $value = is_array($all) ? ($all['display_errors']['global_value'] ?? null) : ini_get('display_errors');

        return is_string($value) ? $value : null;
    }
}
