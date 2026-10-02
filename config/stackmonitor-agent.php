<?php

return [
    'secret' => env('STACKMONITOR_AGENT_SECRET'),
    'path' => env('STACKMONITOR_AGENT_PATH', 'stackmonitor/status'),
    'max_clock_skew' => 300,
    // Send the three most frequent log messages along with the error count;
    // false sends the counts only.
    'log_messages' => env('STACKMONITOR_AGENT_LOG_MESSAGES', true),
];
