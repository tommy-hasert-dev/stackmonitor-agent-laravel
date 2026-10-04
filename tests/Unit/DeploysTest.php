<?php

use Opis\JsonSchema\Validator;
use StackMonitor\Agent\Deploys;
use StackMonitor\Agent\OperationalData;

/** An app folder under the system temp dir: path => [content, mtime]. */
function deployApp(array $files): string
{
    $base = sys_get_temp_dir().'/sm-deploy-'.bin2hex(random_bytes(4));
    mkdir($base.'/bootstrap/cache', 0777, true);

    foreach ($files as $path => [$content, $modified]) {
        $full = $base.'/'.$path;

        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }

        file_put_contents($full, $content);
        touch($full, $modified);
    }

    app()->setBasePath($base);
    app()->useBootstrapPath($base.'/bootstrap');

    return $base;
}

function deployReport(string $base): ?array
{
    return (new Deploys(app(), $base))->report();
}

const DEPLOY_HASH = '3909b7774a00c040a026fb81353de2f5999051e4';

it('takes the deploy file first: its time, its first line as revision', function () {
    $base = deployApp([
        '.stackmonitor-deploy' => ["v2.4.1\nbuilt on ci\n", 1_790_000_000],
        '.git/HEAD' => ['ref: refs/heads/main', 1_780_000_000],
        '.git/refs/heads/main' => [DEPLOY_HASH."\n", 1_780_000_000],
    ]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => 'v2.4.1', 'source' => 'file']);
});

it('takes an empty deploy file as a deploy without revision, a long line cut', function () {
    $base = deployApp(['.stackmonitor-deploy' => ['', 1_790_000_000]]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => null, 'source' => 'file']);

    file_put_contents($base.'/.stackmonitor-deploy', str_repeat('a', 100));

    expect(deployReport($base)['revision'])->toBe(str_repeat('a', Deploys::MAX_REVISION));
});

it('reads the deploy file from another path when configured', function () {
    $base = deployApp(['storage/deployed' => ['abc123', 1_790_000_000]]);
    config(['stackmonitor-agent.deploy_file' => 'storage/deployed']);

    expect(deployReport($base)['revision'])->toBe('abc123');

    config(['stackmonitor-agent.deploy_file' => $base.'/storage/deployed']);

    expect(deployReport($base)['revision'])->toBe('abc123');
});

it('takes the commit of the branch HEAD points to, dated by its ref', function () {
    $base = deployApp([
        '.git/HEAD' => ["ref: refs/heads/main\n", 1_700_000_000],
        '.git/refs/heads/main' => [DEPLOY_HASH."\n", 1_790_000_000],
        'bootstrap/cache/config.php' => ['<?php return [];', 1_795_000_000],
    ]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => DEPLOY_HASH, 'source' => 'git']);
});

it('finds a packed branch and a detached HEAD', function () {
    $base = deployApp([
        '.git/HEAD' => ["ref: refs/heads/main\n", 1_700_000_000],
        '.git/packed-refs' => ["# pack-refs with: peeled fully-peeled sorted\n".DEPLOY_HASH." refs/heads/main\n", 1_790_000_000],
    ]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => DEPLOY_HASH, 'source' => 'git']);

    file_put_contents($base.'/.git/HEAD', DEPLOY_HASH."\n");
    touch($base.'/.git/HEAD', 1_791_000_000);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_791_000_000), 'revision' => DEPLOY_HASH, 'source' => 'git']);
});

it('follows the .git file of a worktree to the shared refs', function () {
    $base = deployApp([
        '.git' => ["gitdir: repo/worktrees/live\n", 1_700_000_000],
        'repo/worktrees/live/HEAD' => ["ref: refs/heads/live\n", 1_700_000_000],
        'repo/worktrees/live/commondir' => ["../..\n", 1_700_000_000],
        'repo/refs/heads/live' => [DEPLOY_HASH."\n", 1_790_000_000],
    ]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => DEPLOY_HASH, 'source' => 'git']);
});

it('falls back to the cached configuration, and reports nothing without any of it', function () {
    $base = deployApp(['bootstrap/cache/config.php' => ['<?php return [];', 1_790_000_000]]);

    expect(deployReport($base))->toBe(['at' => date(DATE_ATOM, 1_790_000_000), 'revision' => null, 'source' => 'config_cache']);

    unlink($base.'/bootstrap/cache/config.php');

    expect(deployReport($base))->toBeNull();
});

it('ignores a Git checkout it cannot make sense of', function () {
    $base = deployApp([
        '.git/HEAD' => ["ref: refs/heads/main\n", 1_700_000_000],
        '.git/refs/heads/main' => ["not a hash\n", 1_790_000_000],
    ]);

    expect(deployReport($base))->toBeNull();
});

it('goes into the operational data and matches the schema', function () {
    $base = deployApp(['.stackmonitor-deploy' => [DEPLOY_HASH, 1_790_000_000]]);
    $extra = (new OperationalData(app(), $base))->report();
    $report = [
        'schema_version' => 1, 'agent_version' => '1.0.0', 'platform' => 'laravel',
        'core' => ['version' => '12.0.0', 'update_available' => null], 'php' => '8.4.0',
        'packages' => [], 'flags' => ['debug' => false, 'environment' => 'production'], 'extra' => $extra,
    ];
    $schema = file_get_contents(__DIR__.'/../../../../schema/agent-report.v1.json');

    expect($extra['deploy']['source'])->toBe('file')
        ->and((new Validator)->validate(json_decode(json_encode($report)), $schema)->isValid())->toBeTrue();
});
