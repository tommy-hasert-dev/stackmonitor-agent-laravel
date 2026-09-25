<?php

use StackMonitor\Agent\Signature;
use StackMonitor\Agent\Tests\TestCase;

it('falls back to stackmonitor/status when STACKMONITOR_AGENT_PATH is empty', function () {
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $headers = [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-Signature' => Signature::forRequest($timestamp, $nonce, TestCase::SECRET),
    ];

    $this->get('/stackmonitor/status', $headers)->assertOk();
});
