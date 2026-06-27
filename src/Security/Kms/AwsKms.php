<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

use Aws\Kms\KmsClient;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Data\GeneratedDataKey;
use Throwable;

/**
 * AWS KMS key-management driver (FR-SEC-02) for production BYOK.
 *
 * Uses **one Customer Master Key (CMK) per tenant**, referenced by an alias
 * (`alias/{prefix}{keyId}`), so crypto-shredding one tenant (scheduling its CMK
 * for deletion) never affects another. DEKs are generated/wrapped/unwrapped
 * inside KMS — the plaintext KEK never leaves AWS (FR-SEC-03).
 *
 * Requires `aws/aws-sdk-php`. Credentials resolve via the standard AWS provider
 * chain (env, profile, IAM role) unless given explicitly in config.
 */
final class AwsKms implements KeyManagement
{
    public function __construct(
        private readonly KmsClient $client,
        private readonly string $aliasPrefix = 'alias/rag-',
        private readonly int $deletionWindowDays = 7,
    ) {}

    public function createKey(string $keyId): void
    {
        if ($this->keyExists($keyId)) {
            return;
        }

        $result = $this->client->createKey([
            'Description' => "RAG Engine tenant KEK [{$keyId}]",
            'KeyUsage' => 'ENCRYPT_DECRYPT',
            'KeySpec' => 'SYMMETRIC_DEFAULT',
        ]);

        $cmkId = (string) $result['KeyMetadata']['KeyId'];

        $this->client->createAlias([
            'AliasName' => $this->alias($keyId),
            'TargetKeyId' => $cmkId,
        ]);

        $this->client->enableKeyRotation(['KeyId' => $cmkId]);
    }

    public function generateDataKey(string $keyId): GeneratedDataKey
    {
        $result = $this->client->generateDataKey([
            'KeyId' => $this->alias($keyId),
            'KeySpec' => 'AES_256',
        ]);

        return new GeneratedDataKey(
            plaintext: (string) $result['Plaintext'],
            wrapped: base64_encode((string) $result['CiphertextBlob']),
            keyId: $keyId,
        );
    }

    public function unwrapDataKey(string $keyId, string $wrappedKey): string
    {
        $result = $this->client->decrypt([
            'KeyId' => $this->alias($keyId),
            'CiphertextBlob' => (string) base64_decode($wrappedKey, true),
        ]);

        return (string) $result['Plaintext'];
    }

    public function wrapDataKey(string $keyId, string $plaintext): string
    {
        $result = $this->client->encrypt([
            'KeyId' => $this->alias($keyId),
            'Plaintext' => $plaintext,
        ]);

        return base64_encode((string) $result['CiphertextBlob']);
    }

    public function rotateKey(string $keyId): void
    {
        // AWS KMS performs the cryptographic rotation; ensure it's enabled. The
        // alias keeps resolving to the rotated material, so wrapped DEKs stay
        // unwrappable without re-wrapping.
        $this->client->enableKeyRotation(['KeyId' => $this->alias($keyId)]);
    }

    public function destroyKey(string $keyId): void
    {
        $alias = $this->alias($keyId);

        // Disable immediately so the key is unusable now, then schedule deletion
        // (AWS enforces a 7–30 day window).
        try {
            $this->client->disableKey(['KeyId' => $alias]);
        } catch (Throwable) {
            // Already disabled / pending deletion — proceed to schedule.
        }

        $this->client->scheduleKeyDeletion([
            'KeyId' => $alias,
            'PendingWindowInDays' => $this->deletionWindowDays,
        ]);
    }

    public function keyExists(string $keyId): bool
    {
        try {
            $this->client->describeKey(['KeyId' => $this->alias($keyId)]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function name(): string
    {
        return 'aws';
    }

    private function alias(string $keyId): string
    {
        // KMS alias names allow [a-zA-Z0-9/_-]; sanitise the tenant id.
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyId) ?? $keyId;

        return $this->aliasPrefix.$safe;
    }
}
