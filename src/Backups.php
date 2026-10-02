<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * The last backup of spatie/laravel-backup (#64), sent in `extra.backup`:
 * the newest backup file on its disks that are local to the server. Remote
 * disks (S3, FTP …) would cost a network request per report and are left
 * alone; with only those the time stays unknown (`readable` false).
 * spatie keeps no record of failed runs, so `last_failed_at` is always null.
 */
final class Backups
{
    public const SOURCE = 'spatie/laravel-backup';

    public function __construct(private readonly Application $app) {}

    /**
     * Null without spatie/laravel-backup, told by its config.
     *
     * @return array{source: string, last_success_at: string|null, last_failed_at: null, readable: bool}|null
     */
    public function report(): ?array
    {
        $name = config('backup.backup.name');
        $disks = config('backup.backup.destination.disks');

        if (! is_string($name) || $name === '' || ! is_array($disks)) {
            return null;
        }

        $newest = null;
        $readable = false;

        foreach ($disks as $disk) {
            if (! is_string($disk) || config("filesystems.disks.{$disk}.driver") !== 'local') {
                continue;
            }

            try {
                $storage = $this->app['filesystem']->disk($disk);

                // spatie's own test for a backup: a zip in the folder named after the app.
                foreach ($storage->allFiles($name) as $file) {
                    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'zip') {
                        $newest = max($newest ?? 0, $storage->lastModified($file));
                    }
                }

                $readable = true;
            } catch (Throwable) {
                continue;
            }
        }

        return [
            'source' => self::SOURCE,
            'last_success_at' => $newest === null ? null : date(DATE_ATOM, $newest),
            'last_failed_at' => null,
            'readable' => $readable,
        ];
    }
}
