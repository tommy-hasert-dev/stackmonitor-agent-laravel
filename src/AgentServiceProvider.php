<?php

namespace StackMonitor\Agent;

use Illuminate\Support\ServiceProvider;

final class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stackmonitor-agent.php', 'stackmonitor-agent');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/stackmonitor-agent.php' => $this->app->configPath('stackmonitor-agent.php'),
        ], 'stackmonitor-agent-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/agent.php');
    }
}
