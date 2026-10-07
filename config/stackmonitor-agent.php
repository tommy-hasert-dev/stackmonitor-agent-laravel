<?php

return [
    'secret' => env('STACKMONITOR_AGENT_SECRET'),
    'path' => env('STACKMONITOR_AGENT_PATH', 'stackmonitor/status'),
    'max_clock_skew' => 300,
    // Send the three most frequent log messages along with the error count;
    // false sends the counts only.
    'log_messages' => env('STACKMONITOR_AGENT_LOG_MESSAGES', true),
    // The file the deploy script writes (#150), relative to the app or
    // absolute; null means .stackmonitor-deploy in the app.
    'deploy_file' => env('STACKMONITOR_AGENT_DEPLOY_FILE'),
    // Lets the dashboard read the start (at most 64 KB) of a suspicious file
    // the agent found itself, to look for signs of malicious code. Off unless
    // switched on here; the dashboard can't switch it on.
    'file_contents' => env('STACKMONITOR_AGENT_FILE_CONTENTS', false),
];
