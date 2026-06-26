<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security;

use Sellinnate\RagEngine\Exceptions\EncryptionException;
use SensitiveParameter;

/**
 * Thin authenticated-encryption helper (AES-256-GCM) used both to wrap DEKs in
 * the KMS and to envelope-encrypt content (FR-SEC-01, NFR-SE-03).
 *
 * Output layout (base64 of): [12-byte IV][16-byte tag][ciphertext].
 */
final class AeadCipher
{
    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly string $cipher = 'aes-256-gcm',
    ) {}

    /**
     * @param  string  $key  32 raw bytes.
     */
    public function encrypt(#[SensitiveParameter] string $key, string $plaintext): string
    {
        $this->assertKey($key);

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new EncryptionException('Encryption failed.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /**
     * @param  string  $key  32 raw bytes.
     */
    public function decrypt(#[SensitiveParameter] string $key, string $payload): string
    {
        $this->assertKey($key);

        $raw = base64_decode($payload, true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            // Generic message (no malformed-vs-tampered distinction) to avoid an
            // information-disclosure oracle on the ciphertext structure.
            throw new EncryptionException('Decryption failed.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            // Wrong key (e.g. KEK rotated/destroyed) or tampered ciphertext —
            // same generic message as the malformed case (no oracle).
            throw new EncryptionException('Decryption failed.');
        }

        return $plaintext;
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) !== 32) {
            throw new EncryptionException('AEAD key must be exactly 32 bytes.');
        }
    }
}
