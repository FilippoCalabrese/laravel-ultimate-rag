<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\TokenizerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tenancy\TenantContext;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RagEngineServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('rag-engine')
            ->hasConfigFile()
            ->hasMigration('create_rag_engine_tables');
    }

    public function packageRegistered(): void
    {
        $this->registerManagers();
        $this->registerSecurity();
        $this->registerTenancy();
        $this->bindDefaultDrivers();

        $this->app->singleton(RagEngine::class, fn ($app) => new RagEngine(
            $app->make(EmbedderManager::class),
            $app->make(VectorStoreManager::class),
            $app->make(RerankerManager::class),
            $app->make(KmsManager::class),
            $app->make(TokenizerManager::class),
            $app->make(LlmManager::class),
            $app->make(EnvelopeEncrypter::class),
            $app->make(TenantContext::class),
        ));

        $this->app->alias(RagEngine::class, 'rag-engine');
    }

    private function registerManagers(): void
    {
        foreach ([
            EmbedderManager::class,
            VectorStoreManager::class,
            RerankerManager::class,
            KmsManager::class,
            TokenizerManager::class,
            LlmManager::class,
        ] as $manager) {
            $this->app->singleton($manager, fn ($app) => new $manager($app));
        }
    }

    private function registerSecurity(): void
    {
        $this->app->singleton(AeadCipher::class, fn ($app) => new AeadCipher(
            (string) $app->make('config')->get('rag-engine.security.cipher', 'aes-256-gcm'),
        ));

        $this->app->singleton(EnvelopeEncrypter::class, fn ($app) => new EnvelopeEncrypter(
            $app->make(KeyManagement::class),
            $app->make(AeadCipher::class),
        ));
    }

    private function registerTenancy(): void
    {
        // Scoped, not singleton: a process-lifetime singleton would let the tenant
        // set in one request/job bleed into the next under Octane/Horizon/RoadRunner.
        // `scoped` is reset per request/job lifecycle.
        $this->app->scoped(TenantContext::class, fn ($app) => new TenantContext(
            (string) $app->make('config')->get('rag-engine.tenancy.default_tenant', 'default'),
        ));
    }

    private function bindDefaultDrivers(): void
    {
        $this->app->bind(Embedder::class, fn ($app) => $app->make(EmbedderManager::class)->driver());
        $this->app->bind(VectorStore::class, fn ($app) => $app->make(VectorStoreManager::class)->driver());
        $this->app->bind(Reranker::class, fn ($app) => $app->make(RerankerManager::class)->driver());
        $this->app->bind(KeyManagement::class, fn ($app) => $app->make(KmsManager::class)->driver());
        $this->app->bind(Tokenizer::class, fn ($app) => $app->make(TokenizerManager::class)->driver());
        $this->app->bind(Llm::class, fn ($app) => $app->make(LlmManager::class)->driver());
    }
}
