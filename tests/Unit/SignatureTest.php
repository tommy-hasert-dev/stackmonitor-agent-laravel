<?php

use StackMonitor\Agent\Signature;

it('signs a file request over timestamp, nonce and path hash', function () {
    $hash = hash('sha256', 'wp-content/uploads/shell.php');

    expect(Signature::forFileRequest('1790000000', '0123456789abcdef0123456789abcdef', $hash, 'secret'))
        ->toBe(hash_hmac('sha256', '1790000000.0123456789abcdef0123456789abcdef.file.'.$hash, 'secret'))
        ->not->toBe(Signature::forRequest('1790000000', '0123456789abcdef0123456789abcdef', 'secret'));
});

it('signs a mail request over timestamp, nonce, token and recipient (#133)', function () {
    expect(Signature::forMailRequest('1790000000', '0123456789abcdef0123456789abcdef', 'ab', 'check@stackmonitor.de', 'secret'))
        ->toBe(hash_hmac('sha256', '1790000000.0123456789abcdef0123456789abcdef.mail.ab.check@stackmonitor.de', 'secret'));
});
