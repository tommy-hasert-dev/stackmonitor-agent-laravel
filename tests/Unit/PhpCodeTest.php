<?php

use Opis\JsonSchema\Validator;
use StackMonitor\Agent\OperationalData;
use StackMonitor\Agent\PhpCode;

/** An app folder under the system temp dir: path => content, a trailing slash makes a directory. */
function phpCodeApp(array $files): string
{
    $base = sys_get_temp_dir().'/sm-php-code-'.bin2hex(random_bytes(4));
    mkdir($base);

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

    return $base;
}

function phpCodeLock(array $packages, array $dev = []): string
{
    return (string) json_encode(['packages' => $packages, 'packages-dev' => $dev]);
}

/** @return array{files: int, bytes: int, entries: list<array{path: string, abs: string, size: int, mtime: int}>|null} */
function phpFiles(string $root, int $maxFiles = PhpCode::MAX_FILES): array
{
    return PhpCode::files($root, $maxFiles, INF);
}

function fingerprintOf(string $root, int $maxFiles = PhpCode::MAX_FILES): ?string
{
    $files = phpFiles($root, $maxFiles);

    return $files['entries'] === null ? null : PhpCode::fingerprint($files['entries']);
}

/** @return array<string, string> path => content */
function unzipped(string $archive): array
{
    $path = tempnam(sys_get_temp_dir(), 'sm-zip-test-');
    file_put_contents($path, $archive);
    $zip = new ZipArchive;
    $zip->open($path);
    $files = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $files[$name] = (string) $zip->getFromIndex($i);
    }

    $zip->close();
    unlink($path);
    ksort($files);

    return $files;
}

it('reports the app and only the packages that are neither on Packagist nor for development', function () {
    $base = phpCodeApp([
        'app/Models/User.php' => '<?php class User {}',
        'composer.lock' => phpCodeLock([
            ['name' => 'laravel/framework', 'version' => 'v13.0.0', 'notification-url' => 'https://packagist.org/downloads/'],
            ['name' => 'acme/billing', 'version' => '2.1.0', 'source' => ['type' => 'git', 'url' => 'git@git.acme.test:acme/billing.git']],
            ['name' => 'acme/gone', 'version' => '1.0.0'],
            ['name' => '../../etc', 'version' => '1.0.0'],
        ], [
            ['name' => 'acme/dev-tools', 'version' => '1.0.0'],
        ]),
        'vendor/laravel/framework/src/Foundation.php' => '<?php',
        'vendor/acme/billing/src/Invoice.php' => '<?php class Invoice {}',
        'vendor/acme/dev-tools/src/Tool.php' => '<?php',
    ]);

    $packages = PhpCode::packages($base);

    expect(array_map(fn (array $p) => [$p['type'], $p['name'], $p['root']], $packages))->toBe([
        ['app', 'app', $base.'/app'],
        ['composer', 'acme/billing', $base.'/vendor/acme/billing'],
    ])
        ->and($packages[0]['id'])->toBe(hash('sha256', 'app:app'))
        ->and($packages[1]['id'])->toBe(hash('sha256', 'composer:acme/billing'));
});

it('reports no package without app folder and composer.lock', function () {
    expect(PhpCode::packages(phpCodeApp(['public/' => null])))->toBe([]);
});

it('lists the PHP files at their path relative to the root, of any case, and nothing else', function () {
    $base = phpCodeApp([
        'app/Models/User.php' => '<?php class User {}',
        'app/Legacy/OLD.PHP' => '<?php',
        'app/views/home.blade.php' => '<div></div>',
        'app/readme.md' => '# app',
        'app/script.js' => 'x',
    ]);

    $files = phpFiles($base.'/app');

    expect(array_column($files['entries'], 'path'))->toBe(['Legacy/OLD.PHP', 'Models/User.php', 'views/home.blade.php'])
        ->and($files['files'])->toBe(3)
        ->and($files['bytes'])->toBe(strlen('<?php class User {}') + strlen('<?php') + strlen('<div></div>'));
});

it('has a stable fingerprint that changes with a PHP file but not with other files', function () {
    $base = phpCodeApp(['app/A.php' => '<?php', 'app/b/B.php' => '<?php // b', 'app/notes.txt' => 'x']);
    $first = fingerprintOf($base.'/app');

    expect($first)->toMatch('/^[a-f0-9]{64}$/')
        ->and(fingerprintOf($base.'/app'))->toBe($first)
        ->and($first)->toBe(hash('sha256', "A.php\t5\t1790000000\nb/B.php\t10\t1790000000\n"));

    file_put_contents($base.'/app/notes.txt', 'changed');
    touch($base.'/app/notes.txt', 1_790_000_500);
    expect(fingerprintOf($base.'/app'))->toBe($first);

    touch($base.'/app/A.php', 1_790_000_500);
    $touched = fingerprintOf($base.'/app');
    expect($touched)->not->toBe($first);

    file_put_contents($base.'/app/C.php', '<?php');
    expect(fingerprintOf($base.'/app'))->not->toBe($touched);
});

it('sends no fingerprint above the file limit but still counts', function () {
    $base = phpCodeApp(['app/A.php' => '<?php', 'app/B.php' => '<?php', 'app/C.php' => '<?php']);

    expect(fingerprintOf($base.'/app', 3))->not->toBeNull()
        ->and(fingerprintOf($base.'/app', 2))->toBeNull()
        ->and(phpFiles($base.'/app', 2))->toMatchArray(['files' => 3, 'bytes' => 15, 'entries' => null]);
});

it('follows a linked package folder but no linked folder or file inside it', function () {
    $outside = phpCodeApp(['secret/Hidden.php' => '<?php', 'package/src/Real.php' => '<?php', 'loose.php' => '<?php']);
    $base = phpCodeApp([
        'composer.lock' => phpCodeLock([['name' => 'acme/local', 'version' => 'dev-main', 'dist' => ['type' => 'path', 'url' => '../local']]]),
        'vendor/acme/' => null,
    ]);
    symlink($outside.'/package', $base.'/vendor/acme/local');
    symlink($outside.'/secret', $outside.'/package/src/linked');
    symlink($outside.'/loose.php', $outside.'/package/src/Loose.php');

    $packages = PhpCode::packages($base);

    expect(array_column($packages, 'name'))->toBe(['acme/local'])
        ->and(array_column(phpFiles($packages[0]['root'])['entries'], 'path'))->toBe(['src/Real.php']);
});

it('stops at the deadline and keeps the last result of a package', function () {
    $base = phpCodeApp(['app/A.php' => '<?php']);
    $packages = PhpCode::packages($base);

    expect(PhpCode::files($base.'/app', 10, 0.0))->toBeNull();

    [$state, $report] = PhpCode::measure([], $packages, 1_790_000_000, 10, 0.0);
    expect($state)->toBe([])->and($report)->toBe([]);

    [$state, $report] = PhpCode::measure([], $packages, 1_790_000_000, 10, INF);
    expect($report)->toHaveCount(1)->and($state[$packages[0]['id']]['at'])->toBe(1_790_000_000);

    file_put_contents($base.'/app/B.php', '<?php');
    [, $later] = PhpCode::measure($state, $packages, 1_790_000_100, 10, 0.0);
    expect($later)->toBe($report);
});

it('packs every PHP file at its path relative to the root', function () {
    $base = phpCodeApp(['app/Models/User.php' => '<?php class User {}', 'app/A.php' => '<?php', 'app/notes.txt' => 'x']);
    $id = hash('sha256', 'app:app');

    $zip = PhpCode::zip($id, $base.'/app', 10, PhpCode::MAX_ARCHIVE_BYTES, true);

    expect($zip)->toMatchArray([
        'id' => $id,
        'fingerprint' => fingerprintOf($base.'/app'),
        'files' => 2,
        'bytes' => strlen('<?php class User {}') + 5,
        'sha256' => hash('sha256', base64_decode($zip['archive'])),
    ])
        ->and(unzipped(base64_decode($zip['archive'])))->toBe(['A.php' => '<?php', 'Models/User.php' => '<?php class User {}']);
});

it('packs an empty package into an empty ZIP', function () {
    $base = phpCodeApp(['app/' => null]);

    $zip = PhpCode::zip('x', $base.'/app', 10, PhpCode::MAX_ARCHIVE_BYTES, true);

    expect($zip['files'])->toBe(0)->and(unzipped(base64_decode($zip['archive'])))->toBe([]);
});

it('sends no ZIP above the limits or without the zip extension', function () {
    $base = phpCodeApp(['app/A.php' => str_repeat(bin2hex(random_bytes(512)), 4), 'app/B.php' => '<?php']);

    expect(PhpCode::zip('x', $base.'/app', 10, 100, true))->toBe(['id' => 'x', 'error' => 'too_large'])
        ->and(PhpCode::zip('x', $base.'/app', 1, PhpCode::MAX_ARCHIVE_BYTES, true))->toBe(['id' => 'x', 'error' => 'too_many_files'])
        ->and(PhpCode::zip('x', $base.'/app', 10, PhpCode::MAX_ARCHIVE_BYTES, false))->toBe(['id' => 'x', 'error' => 'no_zip']);
});

it('hands out only the packages it reports', function () {
    $base = phpCodeApp([
        'app/A.php' => '<?php',
        'composer.lock' => phpCodeLock([['name' => 'laravel/framework', 'version' => 'v13.0.0', 'notification-url' => 'https://packagist.org/downloads/']]),
        'vendor/laravel/framework/F.php' => '<?php',
    ]);
    $code = new PhpCode(app(), $base);

    expect($code->archive(hash('sha256', 'app:app')))->toHaveKey('archive')
        ->and($code->archive(hash('sha256', 'composer:laravel/framework')))->toBeNull()
        ->and($code->archive(hash('sha256', 'app:other')))->toBeNull();
});

it('goes into the operational data and matches the schema', function () {
    config(['stackmonitor-agent.file_contents' => true]);
    $base = phpCodeApp([
        'app/A.php' => '<?php',
        'composer.lock' => phpCodeLock([['name' => 'acme/billing', 'version' => '1.0.0']]),
        'vendor/acme/billing/B.php' => '<?php',
    ]);
    app()->setBasePath($base);

    $phpCode = app(OperationalData::class)->report()['php_code'];
    $schema = json_decode(file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json'));
    $result = (new Validator)->validate(json_decode(json_encode($phpCode)), $schema->properties->extra->properties->php_code);

    expect($phpCode['contents'])->toBeTrue()
        ->and(array_column($phpCode['packages'], 'name'))->toBe(['app', 'acme/billing'])
        ->and($phpCode['packages'][0])->toMatchArray(['type' => 'app', 'files' => 1, 'bytes' => 5])
        ->and($result->isValid())->toBeTrue();
});

it('reuses a fresh fingerprint and puts a package cut off by the deadline behind the others', function () {
    $root = sys_get_temp_dir().'/sm-php-code-'.uniqid();
    mkdir($root.'/a', 0777, true);
    mkdir($root.'/b', 0777, true);
    file_put_contents($root.'/a/A.php', '<?php');
    file_put_contents($root.'/b/B.php', '<?php');
    $a = ['type' => 'composer', 'name' => 'acme/a', 'id' => hash('sha256', 'composer:acme/a'), 'root' => $root.'/a'];
    $b = ['type' => 'composer', 'name' => 'acme/b', 'id' => hash('sha256', 'composer:acme/b'), 'root' => $root.'/b'];

    [$state] = PhpCode::measure([], [$a, $b], 1000, 5000, microtime(true) + 5);
    // Within CACHE_SECONDS nothing is measured again, even with no time left.
    [$again, $report] = PhpCode::measure($state, [$a, $b], 1000 + PhpCode::CACHE_SECONDS - 1, 5000, microtime(true) - 1);

    expect($again)->toBe($state)->and($report)->toHaveCount(2);

    // Cut off: marked as tried, and so measured last next time.
    [$cut] = PhpCode::measure([$a['id'] => ['tried' => 0]], [$a], 5000, 5000, microtime(true) + 5);
    [$tried] = PhpCode::measure([], [$a], 5000, 5000, microtime(true) - 1);

    expect($cut[$a['id']]['at'])->toBe(5000)
        ->and($tried)->toBe([]);

    array_map('unlink', [$root.'/a/A.php', $root.'/b/B.php']);
    rmdir($root.'/a');
    rmdir($root.'/b');
    rmdir($root);
});

it('stops counting past the file limit', function () {
    $root = sys_get_temp_dir().'/sm-php-code-'.uniqid();
    mkdir($root);
    foreach (range(1, 4) as $i) {
        file_put_contents("{$root}/F{$i}.php", '<?php');
    }

    $files = PhpCode::files($root, 2, microtime(true) + 5);

    expect($files['entries'])->toBeNull()->and($files['files'])->toBe(3);

    array_map('unlink', glob($root.'/*.php'));
    rmdir($root);
});
