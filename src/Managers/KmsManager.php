<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\FileKeyStore;
use Sellinnate\RagEngine\Security\Kms\LocalKms;

/**
 * Resolves KMS drivers (FR-SEC-02, decision 6.8).
 *
 * Cloud drivers (AWS/GCP/Azure/Vault) register through {@see extend()} so the
 * core ships without those SDKs as hard dependencies (NFR-ES-04).
 *
 * @extends DriverManager<KeyManagement>
 */
final class KmsManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'kms';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.kms', 'local');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createLocalDriver(array $config): KeyManagement
    {
        $cipher = new AeadCipher((string) $this->app->make('config')->get('rag-engine.security.cipher', 'aes-256-gcm'));

        $store = ($config['store'] ?? 'array') === 'file' && isset($config['keystore'])
            ? new FileKeyStore((string) $config['keystore'])
            : new ArrayKeyStore;

        return new LocalKms($store, $cipher);
    }
}
