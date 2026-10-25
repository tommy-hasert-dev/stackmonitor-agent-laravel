<?php

use StackMonitor\Agent\Tests\EmptyPathTestCase;
use StackMonitor\Agent\Tests\TestCase;

// uses() rather than pest()->extend(): Pest 2, for PHP 8.1, doesn't have pest() yet.
uses(TestCase::class)->in('Feature', 'Unit');
uses(EmptyPathTestCase::class)->in('EmptyPath');
