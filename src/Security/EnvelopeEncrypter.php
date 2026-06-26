<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security;

use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Data\EncryptedPayload;

/**
 * Envelope encryption service (FR-SEC-01).
 *
 * encrypt(): generate a fresh per-document DEK via the KMS, encrypt the content
 * with it, and emit the ciphertext + wrapped DEK. The plaintext DEK exists only
 * for the duration of the call and is never persisted (model-data §8).
 *
 * decrypt(): ask the KMS to unwrap the DEK, then decrypt. If the KEK has been
 * crypto-shredded the unwrap fails and the content is unrecoverable (FR-SEC-04).
 */
final class EnvelopeEncrypter
{
    public function __construct(
        private readonly KeyManagement $kms,
        private readonly AeadCipher $cipher,
    ) {}

    public function encrypt(string $plaintext, string $keyId): EncryptedPayload
    {
        $dataKey = $this->kms->generateDataKey($keyId);

        // The wrapped DEK and the ciphertext must derive from the same DEK.
        $ciphertext = $this->cipher->encrypt($dataKey->plaintext, $plaintext);

        return new EncryptedPayload(
            ciphertext: $ciphertext,
            wrappedDek: $dataKey->wrapped,
            keyId: $keyId,
        );
    }

    public function decrypt(EncryptedPayload $payload): string
    {
        $dek = $this->kms->unwrapDataKey($payload->keyId, $payload->wrappedDek);

        return $this->cipher->decrypt($dek, $payload->ciphertext);
    }
}
