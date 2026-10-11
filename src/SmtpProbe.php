<?php

namespace StackMonitor\Agent;

use RuntimeException;

/**
 * Talks to an SMTP server up to its answer to EHLO (#156) and says goodbye:
 * no login, no mail. Throws when the server can't be reached or answers
 * with an error.
 */
final class SmtpProbe
{
    public static function ehlo(string $host, int $port, bool $tls, int $timeout): void
    {
        if ($host === '') {
            throw new RuntimeException('No SMTP host configured.');
        }

        // The reason comes in $error; the warning would only fill the app's log.
        set_error_handler(fn () => true);

        try {
            $socket = stream_socket_client(($tls ? 'tls' : 'tcp')."://{$host}:{$port}", $errno, $error, $timeout);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            throw new RuntimeException("Connection to {$host}:{$port} failed: ".($error !== '' ? $error : "error {$errno}"));
        }

        try {
            stream_set_timeout($socket, $timeout);
            self::expect($socket, '220');
            fwrite($socket, "EHLO localhost\r\n");
            self::expect($socket, '250');
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    /**
     * Reads one reply, over several lines when the server sends them.
     *
     * @param  resource  $socket
     */
    private static function expect($socket, string $code): void
    {
        do {
            $line = fgets($socket, 1024);

            if ($line === false) {
                throw new RuntimeException(stream_get_meta_data($socket)['timed_out'] ? 'The SMTP server did not answer in time.' : 'The SMTP server closed the connection.');
            }
        } while (strlen($line) > 3 && $line[3] === '-');

        if (! str_starts_with($line, $code)) {
            throw new RuntimeException('The SMTP server answered: '.trim($line));
        }
    }
}
