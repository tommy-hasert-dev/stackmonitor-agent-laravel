<?php

use Opis\JsonSchema\Validator;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\SuspiciousFiles;

/** An app folder under the system temp dir: path => content, a trailing slash makes a directory. */
function suspiciousApp(array $files): string
{
    $base = sys_get_temp_dir().'/sm-suspicious-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $content) {
        $full = $base.'/'.$path;
        $dir = str_ends_with($path, '/') ? $full : dirname($full);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (! str_ends_with($path, '/')) {
            file_put_contents($full, $content);
            touch($full, 1_790_000_000);
        }
    }

    app()->setBasePath($base);
    app()->useStoragePath($base.'/storage');
    app()->usePublicPath($base.'/public');
    config(['filesystems.disks.public' => ['driver' => 'local', 'root' => $base.'/storage/app/public']]);

    return $base;
}

function suspiciousReport(string $base): ?array
{
    return (new SuspiciousFiles(app(), $base))->report();
}

beforeEach(function () {
    $this->travelTo('2026-09-21 14:13:20');
});

it('finds PHP files on the public disk, with path, size and date but not their content', function () {
    $base = suspiciousApp([
        'storage/app/public/avatars/jane.png' => 'png',
        'storage/app/public/avatars/shell.php' => '<?php system($_GET["c"]);',
        'storage/app/public/docs/invoice.php.pdf' => 'x',
        'storage/app/public/.htaccess' => "AddHandler application/x-httpd-php .png\n",
        'storage/app/public/index.php' => "<?php\n// Silence is golden.\n",
    ]);

    $report = suspiciousReport($base);

    expect($report['scanned_at'])->toBe('2026-09-21T14:13:20+00:00')
        ->and($report['total'])->toBe(3)
        ->and($report['files'])->toBe([
            ['path' => 'storage/app/public/.htaccess', 'kind' => 'htaccess', 'size' => 40, 'modified_at' => '2026-09-21T14:13:20+00:00'],
            ['path' => 'storage/app/public/avatars/shell.php', 'kind' => 'php', 'size' => 25, 'modified_at' => '2026-09-21T14:13:20+00:00'],
            ['path' => 'storage/app/public/docs/invoice.php.pdf', 'kind' => 'double_extension', 'size' => 1, 'modified_at' => '2026-09-21T14:13:20+00:00'],
        ])
        ->and(json_encode($report))->not->toContain('system');
});

it('looks through public/storage only when it is a folder of its own', function () {
    $base = suspiciousApp([
        'storage/app/public/a.php' => '<?php',
        'public/' => null,
    ]);
    symlink($base.'/storage/app/public', $base.'/public/storage');

    expect(array_column(suspiciousReport($base)['files'], 'path'))->toBe(['storage/app/public/a.php']);

    unlink($base.'/public/storage');
    mkdir($base.'/public/storage');
    file_put_contents($base.'/public/storage/b.phtml', 'x');
    cache()->forget(SuspiciousFiles::CACHE_KEY);

    expect(array_column(suspiciousReport($base)['files'], 'path'))->toBe(['public/storage/b.phtml', 'storage/app/public/a.php']);
});

it('keeps the result in the cache and scans again only once it is old', function () {
    $base = suspiciousApp(['storage/app/public/x.php' => '<?php']);
    suspiciousReport($base);
    unlink($base.'/storage/app/public/x.php');

    $this->travel(SuspiciousFiles::RESCAN_AFTER - 1)->seconds();
    expect(suspiciousReport($base)['total'])->toBe(1);

    $this->travel(1)->seconds();
    expect(suspiciousReport($base)['total'])->toBe(0);
});

it('spreads a large scan over several reports and reports nothing before the first is complete', function () {
    $base = suspiciousApp([
        'storage/app/public/a/x.php' => '<?php',
        'storage/app/public/b/c.jpg' => 'x',
        'storage/app/public/d/e.jpg' => 'x',
    ]);
    $roots = [$base.'/storage/app/public'];

    $state = SuspiciousFiles::scan([], $roots, $base, 1_790_000_000, 3, INF);
    expect(SuspiciousFiles::forReport($state))->toBeNull();

    $state = SuspiciousFiles::scan($state, $roots, $base, 1_790_000_100, 3, INF);
    expect(SuspiciousFiles::forReport($state))->toMatchArray(['scanned_at' => '2026-09-21T14:15:00+00:00', 'scanned' => 6, 'total' => 1]);
});

it('reports an empty result without a public disk folder', function () {
    $base = suspiciousApp(['storage/' => null]);

    expect(suspiciousReport($base))->toMatchArray(['scanned' => 0, 'total' => 0, 'files' => []]);
});

it('goes into the operational data and matches the schema', function () {
    $base = suspiciousApp(['storage/app/public/x.php' => '<?php']);
    app()->instance(SuspiciousFiles::class, new SuspiciousFiles(app(), $base));

    $extra = app(OperationalData::class)->report();
    $report = [
        'schema_version' => 1, 'agent_version' => '1.0.0', 'platform' => 'laravel',
        'core' => ['version' => '11.0.0', 'update_available' => null], 'php' => '8.3.0', 'packages' => [],
        'flags' => ['debug' => false, 'environment' => 'production'],
        'extra' => ['suspicious_files' => $extra['suspicious_files']],
    ];
    $schema = file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json');

    expect($extra['suspicious_files']['total'])->toBe(1)
        ->and((new Validator)->validate(json_decode(json_encode($report)), $schema)->isValid())->toBeTrue();
});
