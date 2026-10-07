<?php

namespace StackMonitor\Agent;

final class Signature
{
    public static function forRequest(string $timestamp, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce, $secret);
    }

    /**
     * A request for the content of one suspicious file: the path hash is
     * signed along, so it can't be swapped on the way.
     */
    public static function forFileRequest(string $timestamp, string $nonce, string $pathHash, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.file.'.$pathHash, $secret);
    }

    public static function forResponse(string $body, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $body.'.'.$nonce, $secret);
    }
}
