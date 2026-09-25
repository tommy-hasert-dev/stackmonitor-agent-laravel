<?php

namespace StackMonitor\Agent\Tests;

/**
 * A dedicated TestCase that configures an empty STACKMONITOR_AGENT_PATH before the
 * package's service provider boots (and therefore before routes/agent.php is loaded),
 * so the route-registration fallback can be exercised.
 */
class EmptyPathTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('stackmonitor-agent.path', '');
    }
}
