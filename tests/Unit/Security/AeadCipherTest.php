<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Security\AeadCipher;

beforeEach(function () {
    $this->cipher = new AeadCipher;
    $this->key = random_bytes(32);
});

it('round-trips plaintext', function () {
    $ciphertext = $this->cipher->encrypt($this->key, 'secret message');

    expect($this->cipher->decrypt($this->key, $ciphertext))->toBe('secret message');
});

it('produces different ciphertext each time (random IV)', function () {
    $a = $this->cipher->encrypt($this->key, 'same');
    $b = $this->cipher->encrypt($this->key, 'same');

    expect($a)->not->toBe($b)
        ->and($this->cipher->decrypt($this->key, $a))->toBe('same')
        ->and($this->cipher->decrypt($this->key, $b))->toBe('same');
});

it('fails to decrypt with the wrong key', function () {
    $ciphertext = $this->cipher->encrypt($this->key, 'secret');

    $this->cipher->decrypt(random_bytes(32), $ciphertext);
})->throws(EncryptionException::class);

it('detects tampering via the auth tag', function () {
    $ciphertext = $this->cipher->encrypt($this->key, 'secret');
    $raw = base64_decode($ciphertext, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';

    $this->cipher->decrypt($this->key, base64_encode($raw));
})->throws(EncryptionException::class);

it('rejects keys that are not 32 bytes', function () {
    $this->cipher->encrypt('short', 'data');
})->throws(EncryptionException::class, 'must be exactly 32 bytes');

it('rejects malformed payloads', function () {
    $this->cipher->decrypt($this->key, 'not-base64-or-too-short');
})->throws(EncryptionException::class);
