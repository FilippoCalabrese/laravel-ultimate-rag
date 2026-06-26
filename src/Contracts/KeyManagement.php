<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\GeneratedDataKey;

/**
 * KMS abstraction (FR-SEC-02). Implementations: AWS KMS, GCP KMS, Azure Key
 * Vault, HashiCorp Vault and a local driver for dev/test.
 *
 * The engine never holds a KEK in plaintext at rest (FR-SEC-03): the KEK stays
 * inside the KMS and is only ever used there to wrap/unwrap DEKs.
 */
interface KeyManagement
{
    /**
     * Create a tenant/document KEK if it does not yet exist. Idempotent.
     */
    public function createKey(string $keyId): void;

    /**
     * Generate a new DEK, returning both its plaintext (transient) and the
     * form wrapped by the KEK identified by $keyId.
     */
    public function generateDataKey(string $keyId): GeneratedDataKey;

    /**
     * Unwrap a previously wrapped DEK using the KEK. Throws if the KEK was
     * destroyed (crypto-shredding, FR-SEC-04).
     */
    public function unwrapDataKey(string $keyId, string $wrappedKey): string;

    /**
     * Wrap a plaintext DEK with the current KEK version. Used to re-wrap DEKs
     * during key rotation (FR-SEC-05) without re-ingesting content.
     */
    public function wrapDataKey(string $keyId, string $plaintext): string;

    /**
     * Rotate the KEK identified by $keyId, creating a new key version while
     * leaving existing wrapped DEKs unwrappable via re-wrap (FR-SEC-05).
     */
    public function rotateKey(string $keyId): void;

    /**
     * Permanently destroy the KEK, rendering every DEK wrapped by it — and thus
     * all derived ciphertext — irrecoverable (crypto-shredding, FR-SEC-04).
     */
    public function destroyKey(string $keyId): void;

    /**
     * Whether the KEK currently exists and can unwrap.
     */
    public function keyExists(string $keyId): bool;

    public function name(): string;
}
