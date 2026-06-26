<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

/**
 * File-backed KEK store for single-node dev / on-prem. Each KEK is one file;
 * crypto-shredding deletes the file (FR-SEC-04). Not for production multi-node.
 */
final class FileKeyStore implements KeyStore
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0700, true);
        }
    }

    public function has(string $keyId): bool
    {
        return is_file($this->path($keyId));
    }

    public function get(string $keyId): ?string
    {
        $path = $this->path($keyId);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : (string) base64_decode($contents, true);
    }

    public function put(string $keyId, string $material): void
    {
        file_put_contents($this->path($keyId), base64_encode($material), LOCK_EX);
        chmod($this->path($keyId), 0600);
    }

    public function forget(string $keyId): void
    {
        $path = $this->path($keyId);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(string $keyId): string
    {
        return rtrim($this->directory, '/').'/'.hash('sha256', $keyId).'.kek';
    }
}
