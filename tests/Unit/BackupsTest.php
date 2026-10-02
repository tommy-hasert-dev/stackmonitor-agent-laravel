<?php

use StackMonitor\Agent\Backups;
use StackMonitor\Agent\OperationalData;

function backups(): ?array
{
    return app(Backups::class)->report();
}

function backupDisk(string $name, string $driver = 'local'): string
{
    $root = sys_get_temp_dir().'/sm-backup-'.bin2hex(random_bytes(4));
    mkdir($root);
    config(["filesystems.disks.{$name}" => ['driver' => $driver, 'root' => $root]]);

    return $root;
}

function backupFile(string $root, string $path, int $modified): void
{
    @mkdir(dirname("{$root}/{$path}"), 0777, true);
    file_put_contents("{$root}/{$path}", 'zip');
    touch("{$root}/{$path}", $modified);
}

beforeEach(function () {
    config(['backup' => null]);
});

it('reports nothing without spatie/laravel-backup', function () {
    expect(backups())->toBeNull();
});

it('reports the newest backup on the local disks', function () {
    $one = backupDisk('backups');
    $two = backupDisk('archive');
    backupFile($one, 'my-app/2026-09-30-02-00-00.zip', 1_790_000_000);
    backupFile($two, 'my-app/2026-10-01-02-00-00.zip', 1_790_086_400);
    // Neither a backup nor of this app.
    backupFile($one, 'my-app/notes.txt', 1_790_200_000);
    backupFile($one, 'other-app/2026-10-02-02-00-00.zip', 1_790_200_000);
    config(['backup.backup' => ['name' => 'my-app', 'destination' => ['disks' => ['backups', 'archive']]]]);

    expect(backups())->toBe([
        'source' => 'spatie/laravel-backup',
        'last_success_at' => date(DATE_ATOM, 1_790_086_400),
        'last_failed_at' => null,
        'readable' => true,
    ]);
});

it('reports no backup when the local disks hold none', function () {
    backupDisk('backups');
    config(['backup.backup' => ['name' => 'my-app', 'destination' => ['disks' => ['backups']]]]);

    expect(backups())->toMatchArray(['last_success_at' => null, 'readable' => true]);
});

it('leaves remote disks alone', function () {
    $local = backupDisk('backups');
    $remote = backupDisk('s3', 's3');
    backupFile($local, 'my-app/2026-09-30-02-00-00.zip', 1_790_000_000);
    config(['backup.backup' => ['name' => 'my-app', 'destination' => ['disks' => ['backups', 's3']]]]);

    expect(backups())->toMatchArray(['last_success_at' => date(DATE_ATOM, 1_790_000_000), 'readable' => true]);
});

it('cannot tell the last backup when they only go to remote disks', function () {
    backupDisk('s3', 's3');
    config(['backup.backup' => ['name' => 'my-app', 'destination' => ['disks' => ['s3']]]]);

    expect(backups())->toBe([
        'source' => 'spatie/laravel-backup',
        'last_success_at' => null,
        'last_failed_at' => null,
        'readable' => false,
    ]);
});

it('is sent in the report', function () {
    $root = backupDisk('backups');
    backupFile($root, 'my-app/2026-09-30-02-00-00.zip', 1_790_000_000);
    config(['backup.backup' => ['name' => 'my-app', 'destination' => ['disks' => ['backups']]]]);

    expect(app(OperationalData::class)->report()['backup'])->toMatchArray(['source' => 'spatie/laravel-backup']);
});
