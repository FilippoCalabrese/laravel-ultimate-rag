<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Aws\Kms\KmsClient;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\Kms\ArrayKeyStore;
use Sellinnate\RagEngine\Security\Kms\AwsKms;
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
            ? new FileKeyStore((string) $config['keystore'], $cipher, $config['master_key'] ?? null)
            : new ArrayKeyStore;

        return new LocalKms($store, $cipher);
    }

    /**
     * AWS KMS driver. Requires `aws/aws-sdk-php`. Credentials resolve via the
     * standard AWS provider chain unless `key`/`secret` are given in config.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createAwsDriver(array $config): KeyManagement
    {
        if (! class_exists(KmsClient::class)) {
            throw new RagException('The AWS KMS driver requires aws/aws-sdk-php (composer require aws/aws-sdk-php).');
        }

        $args = [
            'region' => (string) ($config['region'] ?? 'us-east-1'),
            'version' => (string) ($config['version'] ?? 'latest'),
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $args['credentials'] = ['key' => (string) $config['key'], 'secret' => (string) $config['secret']];
        }

        return new AwsKms(
            new KmsClient($args),
            aliasPrefix: (string) ($config['alias_prefix'] ?? 'alias/rag-'),
            deletionWindowDays: (int) ($config['deletion_window_days'] ?? 7),
        );
    }
}
