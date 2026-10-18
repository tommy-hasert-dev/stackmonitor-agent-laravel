<?php

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use StackMonitor\Agent\Signature;
use StackMonitor\Agent\TestMail;
use StackMonitor\Agent\Tests\TestCase;
use Symfony\Component\Mime\Email;

const MAIL_TOKEN = '0123456789abcdef0123456789abcdef';

/** @return array<string, string> */
function mailHeaders(string $token = MAIL_TOKEN, string $to = 'check@stackmonitor.de', ?string $signedTo = null): array
{
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));

    return [
        'X-Monitor-Timestamp' => $timestamp,
        'X-Monitor-Nonce' => $nonce,
        'X-Monitor-Mail' => $token,
        'X-Monitor-Mail-To' => $to,
        'X-Monitor-Signature' => Signature::forMailRequest($timestamp, $nonce, $token, $signedTo ?? $to, TestCase::SECRET),
    ];
}

beforeEach(function () {
    config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array'], 'mail.from' => ['address' => 'shop@example.com', 'name' => 'Shop']]);
});

it('sends a test mail through the app mailer and says so, signed (#133)', function () {
    $sent = [];
    Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent) {
        $sent[] = $event->message;
    });
    $headers = mailHeaders();

    $response = $this->get('/stackmonitor/status', $headers);

    $response->assertOk();
    $body = $response->getContent();
    expect(json_decode($body, true))->toBe(['token' => MAIL_TOKEN, 'sent' => true, 'error' => null, 'transport' => 'array'])
        ->and($response->headers->get('X-Monitor-Signature'))->toBe(Signature::forResponse($body, $headers['X-Monitor-Nonce'], TestCase::SECRET))
        ->and($sent)->toHaveCount(1);

    /** @var Email $mail */
    $mail = $sent[0];
    expect($mail->getTo()[0]->getAddress())->toBe('check@stackmonitor.de')
        ->and($mail->getSubject())->toBe(TestMail::SUBJECT)
        ->and($mail->getHeaders()->get(TestMail::HEADER)?->getBodyAsString())->toBe(MAIL_TOKEN)
        ->and($mail->getTextBody())->toContain(MAIL_TOKEN);
});

it('tells the error of the mailer instead of a mail', function () {
    config(['mail.default' => 'broken', 'mail.mailers.broken' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

    $answer = $this->get('/stackmonitor/status', mailHeaders())->assertOk()->json();

    expect($answer['sent'])->toBeFalse()
        ->and($answer['transport'])->toBe('smtp')
        ->and($answer['error'])->toContain('127.0.0.1');
});

it('sends one test mail a minute at most', function () {
    $this->get('/stackmonitor/status', mailHeaders())->assertOk();

    expect($this->get('/stackmonitor/status', mailHeaders())->json())
        ->toMatchArray(['sent' => false, 'error' => 'too_soon']);
});

it('rejects a mail request whose recipient was swapped on the way', function () {
    $this->get('/stackmonitor/status', mailHeaders(to: 'spam@example.org', signedTo: 'check@stackmonitor.de'))->assertNotFound();
});

it('rejects a mail request signed like a report', function () {
    $headers = mailHeaders();
    $headers['X-Monitor-Signature'] = Signature::forRequest($headers['X-Monitor-Timestamp'], $headers['X-Monitor-Nonce'], TestCase::SECRET);

    $this->get('/stackmonitor/status', $headers)->assertNotFound();
});

it('rejects a malformed token or recipient', function (string $token, string $to) {
    $this->get('/stackmonitor/status', mailHeaders($token, $to))->assertNotFound();
})->with([
    'short token' => ['abc', 'check@stackmonitor.de'],
    'no address' => [MAIL_TOKEN, 'stackmonitor'],
    'no recipient' => [MAIL_TOKEN, ''],
]);
