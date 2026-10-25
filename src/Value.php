<?php

namespace StackMonitor\Agent;

/**
 * Values the agent doesn't control - from the app's config, the cache, the
 * database, Redis or a JSON file - checked in one place instead of cast
 * wherever they are read (#265). A number may come as a string, as `.env`
 * and PDO give them; anything that isn't a number counts as missing and
 * takes the default, where a cast would quietly make "abc" 0 or "60s" 60.
 */
final class Value
{
    /**
     * An int, a finite float (cut to its whole part) or a numeric string;
     * the default for anything else.
     *
     * @template T of int|null
     *
     * @param  T  $default
     * @return int|T
     */
    public static function int(mixed $value, ?int $default = 0): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            $int = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($int)) {
                return $int;
            }

            $value = is_numeric($value) ? (float) $value : null;
        }

        return is_float($value) && is_finite($value) && abs($value) < PHP_INT_MAX ? (int) $value : $default;
    }

    /**
     * A string as it is, a number in its usual form; the default for
     * anything else.
     *
     * @template T of string|null
     *
     * @param  T  $default
     * @return string|T
     */
    public static function string(mixed $value, ?string $default = ''): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => $default,
        };
    }

    /**
     * An array with only its string keys, as config and JSON objects have
     * them; an empty one for anything else.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string', ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * The value when it is an array, else an empty one.
     *
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
