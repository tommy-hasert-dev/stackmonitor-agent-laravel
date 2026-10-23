<?php

namespace StackMonitor\Agent;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends a test mail through the app's own mailer (#133), the way its
 * contact form or order confirmation would, to the address StackMonitor
 * named. StackMonitor fetches it from its mailbox and tells whether and
 * how fast it arrived. The text is fixed, only token and address come
 * with the request; one mail a minute at most.
 */
final class TestMail
{
    public const SUBJECT = '[StackMonitor] Testmail – bitte ignorieren';

    /** The header carrying the token StackMonitor finds the mail by. */
    public const HEADER = 'X-StackMonitor-Test';

    /** Seconds between two test mails, so a leaked secret can't send many. */
    public const MIN_INTERVAL = 60;

    /** The longest error sent, without the credentials of the app's config. */
    public const MAX_ERROR = 300;

    public function __construct(private readonly Application $app) {}

    /**
     * Whether a request names a token and a recipient the agent sends to.
     */
    public static function valid(string $token, string $to): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1
            && strlen($to) <= 254
            && preg_match('/[\r\n]/', $to) !== 1
            && filter_var($to, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{token: string, sent: bool, error: string|null, transport: string|null}
     */
    public function send(string $token, string $to): array
    {
        $mailer = config('mail.default');
        $transport = is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;
        $result = ['token' => $token, 'sent' => false, 'error' => null, 'transport' => is_string($transport) ? $transport : null];

        if (! $this->app->bound('mail.manager')) {
            return [...$result, 'error' => 'no_mailer'];
        }

        if (! OnceStore::get()->add('stackmonitor-agent:test-mail', true, self::MIN_INTERVAL)) {
            return [...$result, 'error' => 'too_soon'];
        }

        try {
            Mail::raw(self::body($token), function (Message $message) use ($to, $token) {
                $message->to($to)->subject(self::SUBJECT);
                $message->getSymfonyMessage()->getHeaders()->addTextHeader(self::HEADER, $token);
            });
        } catch (Throwable $e) {
            return [...$result, 'error' => Secrets::fromConfig()->clean($e->getMessage(), self::MAX_ERROR) ?? $e::class];
        }

        return [...$result, 'sent' => true];
    }

    private static function body(string $token): string
    {
        $app = (string) config('app.url');

        return 'Diese Mail hat StackMonitor über den Mailversand von '.($app !== '' ? $app : 'dieser Website')
            .' verschickt, um zu prüfen, ob Mails aus Kontaktformular oder Shop ankommen. '
            ."Sie geht an ein Postfach von StackMonitor und kann ignoriert werden.\n\n"
            ."Kennung: {$token}\n";
    }
}
