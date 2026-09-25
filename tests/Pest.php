<?php

use StackMonitor\Agent\Tests\EmptyPathTestCase;
use StackMonitor\Agent\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
pest()->extend(EmptyPathTestCase::class)->in('EmptyPath');
