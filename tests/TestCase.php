<?php

namespace StackMonitor\Agent\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;
use StackMonitor\Agent\AgentServiceProvider;
use StackMonitor\Agent\OnceStore;
use StackMonitor\Agent\ReportBuilder;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'c3f1c2d4e5b6a7980c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b';

    protected function getPackageProviders($app): array
    {
        return [AgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('stackmonitor-agent.secret', self::SECRET);
        $app['config']->set('app.debug', false);
        $app['config']->set('cache.default', 'array');
        // Testbench 9 and later pick it by themselves, Testbench 8 (Laravel 10) would use MySQL.
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The array store keeps nothing beyond a test, the agent's files would.
        (new Filesystem)->deleteDirectory(storage_path(OnceStore::PATH));

        $this->app->instance(ReportBuilder::class, new ReportBuilder($this->app, __DIR__.'/fixtures'));
    }
}
