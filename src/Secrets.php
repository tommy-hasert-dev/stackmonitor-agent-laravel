<?php

namespace StackMonitor\Agent;

/**
 * Takes the secrets out of a message before it leaves the server: the
 * values of the config keys that hold credentials (also URL-encoded, as
 * they show up in a request URL), user and password in a URL, and values
 * named as a password, token, key or Authorization header in the text.
 */
final class Secrets
{
    /** Config keys whose values never show up in a message. */
    public const KEYS = ['password', 'passwd', 'secret', 'key', 'token', 'username', 'url', 'passphrase', 'privatekey', 'private_key'];

    /** Endings of config keys that hold a credential too, e.g. `client_secret`, `api_key`. */
    private const KEY_ENDINGS = ['_password', '_secret', '_token', '_passphrase', 'api_key', 'apikey', 'private_key'];

    /** Values shorter than this are left alone, they would mask ordinary words. */
    private const MIN_LENGTH = 4;

    /**
     * Values named in the text: `password=hunter2`, `"token": "abc"`,
     * `Authorization: Basic …`, `Bearer abc`. Only with `=` or `:` after
     * the name, so "Invalid token" or "token mismatch" stay as they are.
     *
     * @var array<string, string>
     */
    private const NAMED = [
        '/\b(bearer)\s+(?!token\b)[A-Za-z0-9._~+\/\-]+=*/i' => '$1 ***',
        '/\b(authorization["\']?\s*[:=]\s*(?:(?:basic|digest|token)\s+)?)(?!bearer\b)(?!\*\*\*)[^\s,;]+/i' => '$1***',
        '/\b([A-Za-z0-9_\-]*(?:password|passwd|pwd|secret|token|api[_\-]?key|passphrase|private[_\-]?key)[A-Za-z0-9_\-]*["\']?\s*[:=]\s*)(?!\*\*\*)(\'[^\']*\'|"[^"]*"|[^\s,;&\'"]+)/i' => '$1***',
    ];

    /** @var list<string> */
    private array $values = [];

    /**
     * The secrets of the whole app config, for messages that may come from
     * anywhere in the app (a scheduled task, the mailer).
     */
    public static function fromConfig(): self
    {
        $secrets = new self;
        $secrets->collect((array) config()->all());

        return $secrets;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function collect(array $config): self
    {
        $this->gather($config);

        $values = array_values(array_unique($this->values));
        // The longest first, so a secret that contains another goes as a whole.
        usort($values, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $this->values = $values;

        return $this;
    }

    /**
     * One line of at most $max characters, without the secrets; null for an empty one.
     */
    public function clean(?string $message, int $max): ?string
    {
        if ($message === null) {
            return null;
        }

        $message = trim((string) preg_replace('/\s+/', ' ', $message));
        $message = str_replace($this->values, '***', $message);
        $message = (string) preg_replace('#//[^/@\s]+@#', '//***@', $message);
        $message = self::named($message);

        return $message === '' ? null : mb_substr($message, 0, $max);
    }

    /**
     * The message without the values named as a password, token, key or
     * Authorization header.
     */
    public static function named(string $message): string
    {
        return (string) preg_replace(array_keys(self::NAMED), array_values(self::NAMED), $message);
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function gather(array $config): void
    {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $this->gather($value);
            } elseif (is_string($key) && is_string($value) && strlen($value) >= self::MIN_LENGTH && self::isSecret(strtolower($key), $value)) {
                array_push($this->values, $value, rawurlencode($value), urlencode($value));
            }
        }
    }

    private static function isSecret(string $key, string $value): bool
    {
        // A URL without user or password is no secret, only the ones of a database or Redis are.
        if ($key === 'url') {
            return str_contains($value, '@');
        }

        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (self::KEY_ENDINGS as $ending) {
            if (str_ends_with($key, $ending)) {
                return true;
            }
        }

        return false;
    }
}
