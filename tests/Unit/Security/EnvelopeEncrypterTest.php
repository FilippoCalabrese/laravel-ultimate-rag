<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

beforeEach(function () {
    $this->kms = new LocalKms(new ArrayKeyStore, new AeadCipher);
    $this->encrypter = new EnvelopeEncrypter($this->kms, new AeadCipher);
});

it('envelope-encrypts and decrypts content', function () {
    $payload = $this->encrypter->encrypt('confidential document body', 'tenant-1');

    expect($payload)->toBeInstanceOf(EncryptedPayload::class)
        ->and($payload->keyId)->toBe('tenant-1')
        ->and($payload->ciphertext)->not->toContain('confidential')
        ->and($this->encrypter->decrypt($payload))->toBe('confidential document body');
});

it('uses a fresh DEK per document (different wrapped DEKs)', function () {
    $a = $this->encrypter->encrypt('doc a', 'tenant-1');
    $b = $this->encrypter->encrypt('doc b', 'tenant-1');

    expect($a->wrappedDek)->not->toBe($b->wrappedDek);
});

it('cannot decrypt after the tenant key is crypto-shredded', function () {
    $payload = $this->encrypter->encrypt('to be forgotten', 'tenant-x');

    $this->kms->destroyKey('tenant-x');

    $this->encrypter->decrypt($payload);
})->throws(EncryptionException::class);

it('survives a serialization round-trip of the payload', function () {
    $payload = $this->encrypter->encrypt('persisted', 'tenant-1');

    $restored = EncryptedPayload::fromArray($payload->toArray());

    expect($this->encrypter->decrypt($restored))->toBe('persisted');
});
