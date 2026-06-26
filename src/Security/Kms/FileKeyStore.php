<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security\Kms;

use Sellinnate\RagEngine\Security\AeadCipher;
use SensitiveParameter;

/**
 * File-backed KEK store for single-node dev / on-prem. Each KEK is one file;
 * crypto-shredding deletes the file (FR-SEC-04). Not for production multi-node.
 *
 * When a master key is provided (config `rag-engine.kms.local.master_key`) the
 * KEK material is encrypted at rest with AES-256-GCM (FR-SEC-03) so the engine
 * never holds a KEK in plaintext on disk; otherwise it is stored base64-encoded
 * (dev only). The file is written atomically (temp + rename) with 0600 perms.
 */
final class FileKeyStore implements KeyStore
{
    private readonly ?string $masterKey;

    public function __construct(
        private readonly string $directory,
        private readonly ?AeadCipher $cipher = null,
        #[SensitiveParameter] ?string $masterKey = null,
    ) {
        // Derive a fixed 32-byte key from the master secret for the AEAD cipher.
        $this->masterKey = ($masterKey !== null && $masterKey !== '')
            ? hash('sha256', $masterKey, true)
            : null;

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

        if ($contents === false) {
            return null;
        }

        return $this->cipher !== null && $this->masterKey !== null
            ? $this->cipher->decrypt($this->masterKey, $contents)
            : (string) base64_decode($contents, true);
    }

    public function put(string $keyId, string $material): void
    {
        $encoded = $this->cipher !== null && $this->masterKey !== null
            ? $this->cipher->encrypt($this->masterKey, $material)
            : base64_encode($material);

        $path = $this->path($keyId);
        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        file_put_contents($tmp, $encoded, LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $path); // atomic
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
