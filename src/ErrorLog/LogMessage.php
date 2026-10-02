<?php

namespace StackMonitor\Agent\ErrorLog;

/**
 * Turns a log message into something that can leave the site: one line, with
 * e-mail addresses, URLs, quoted values, IDs, tokens, IPs, numbers, SQL and
 * directories replaced, capped at MAX_LENGTH. Equal errors with different
 * values end up as the same message, so they can be counted together.
 */
final class LogMessage
{
    public const MAX_LENGTH = 200;

    /** Only the start of a very long line is cleaned; the rest would be cut anyway. */
    public const MAX_INPUT = 1000;

    /** @var array<string, string> */
    private const REPLACEMENTS = [
        // Laravel's QueryException appends the query with its bindings filled in.
        '/\((?:Connection: [^,)]*, )?SQL: .*$/' => '(SQL: …)',
        '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/' => '<email>',
        '#\b[a-z][a-z0-9+.\-]*://\S+#i' => '<url>',
        // Not after a letter, so "can't" stays.
        "/(?<![A-Za-z])'[^']*'/" => "'?'",
        '/"[^"]*"/' => '"?"',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
        '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '<ip>',
        // Absolute paths keep their file name only.
        '#(?<![\w.])(?:[A-Za-z]:)?(?:[\\\\/][\w.\-@~]+)*[\\\\/]([\w.\-@~]+)#' => '…/$1',
        // Long strings of letters and digits: keys, hashes, session IDs.
        '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{20,}\b/' => '<token>',
        '/\b\d+(?:[.,]\d+)*\b/' => 'N',
    ];

    public static function normalize(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', mb_substr($message, 0, self::MAX_INPUT, 'UTF-8')));

        foreach (self::REPLACEMENTS as $pattern => $replacement) {
            $message = (string) preg_replace($pattern, $replacement, $message);
        }

        return mb_substr($message, 0, self::MAX_LENGTH, 'UTF-8');
    }
}
