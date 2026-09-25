<?php

namespace StackMonitor\Agent;

final class Signature
{
    public static function forRequest(string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce, $secret);
    }

    public static function forResponse(string $body, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $body.'.'.$nonce, $secret);
    }
}
