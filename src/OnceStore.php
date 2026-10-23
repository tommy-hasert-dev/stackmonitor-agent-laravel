<?php

namespace StackMonitor\Agent;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;

/**
 * The cache for what may happen only once: the nonces of signed requests,
 * so none can be replayed, and the minute between two test mails. That is
 * the app's default store, unless it keeps nothing beyond the request
 * (array, null): then files under storage/, so the protection holds
 * whatever the app caches in.
 */
final class OnceStore
{
    /** Where the nonces go when the app's store keeps nothing, under storage_path(). */
    public const PATH = 'framework/cache/stackmonitor-agent';

    public static function get(): Repository
    {
        $default = config('cache.default');
        $driver = is_string($default) ? config("cache.stores.{$default}.driver") : null;

        if (is_string($driver) && ! in_array($driver, ['array', 'null'], true)) {
            return Cache::store();
        }

        // FileStore::add() takes an exclusive lock on the file, so it stays atomic.
        return new CacheRepository(new FileStore(new Filesystem, storage_path(self::PATH)));
    }
}
