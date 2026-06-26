<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

/**
 * In-memory KEK store (NFR-TE-01): deterministic, zero I/O, ideal for tests.
 */
final class ArrayKeyStore implements KeyStore
{
    /** @var array<string, string> */
    private array $keys = [];

    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public function get(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }

    public function put(string $keyId, string $material): void
    {
        $this->keys[$keyId] = $material;
    }

    public function forget(string $keyId): void
    {
        unset($this->keys[$keyId]);
    }
}
