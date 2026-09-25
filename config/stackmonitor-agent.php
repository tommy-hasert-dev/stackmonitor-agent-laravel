<?php

return [
    'secret' => env('STACKMONITOR_AGENT_SECRET'),
    'path' => env('STACKMONITOR_AGENT_PATH', 'stackmonitor/status'),
    'max_clock_skew' => 300,
];
