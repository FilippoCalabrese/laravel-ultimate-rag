<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

/**
 * Backing store for the local KMS driver's KEK material. Abstracted so dev/test
 * can use an in-memory store and on-prem can use a file/secret store.
 */
interface KeyStore
{
    public function has(string $keyId): bool;

    public function get(string $keyId): ?string;

    public function put(string $keyId, string $material): void;

    public function forget(string $keyId): void;
}
