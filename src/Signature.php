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

    /**
     * A request to send a test mail (#133): token and recipient are signed
     * along, so neither can be swapped on the way.
     */
    public static function forMailRequest(string $timestamp, string $nonce, string $token, string $to, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.mail.'.$token.'.'.$to, $secret);
    }

    /**
     * A request about entry points (#160): the encoded list is signed
     * along, so nobody on the way can change what is asked.
     */
    public static function forReachRequest(string $timestamp, string $nonce, string $points, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.reach.'.$points, $secret);
    }

    public static function forResponse(string $body, string $nonce, string $secret): string
    {
        return hash_hmac('sha256', $body.'.'.$nonce, $secret);
    }
}
