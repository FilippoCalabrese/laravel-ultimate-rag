<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine;

use Illuminate\Http\Client\Factory;
use Sellinnate\RagEngine\Chunking\ChunkingService;
use Sellinnate\RagEngine\Chunking\ContextualHeaderEnricher;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Ingestion\SourceFactory;
use Sellinnate\RagEngine\Managers\ChunkerManager;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\TokenizerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Observability\UsageRecorder;
use Sellinnate\RagEngine\Parsing\CsvParser;
use Sellinnate\RagEngine\Parsing\DocxParser;
use Sellinnate\RagEngine\Parsing\HtmlParser;
use Sellinnate\RagEngine\Parsing\JsonParser;
use Sellinnate\RagEngine\Parsing\MarkdownParser;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Parsing\PdfParser;
use Sellinnate\RagEngine\Parsing\PlainTextParser;
use Sellinnate\RagEngine\Parsing\XmlParser;
use Sellinnate\RagEngine\Preprocessing\LanguageDetector;
use Sellinnate\RagEngine\Preprocessing\PiiRedactor;
use Sellinnate\RagEngine\Preprocessing\PreprocessingPipeline;
use Sellinnate\RagEngine\Preprocessing\TextCleaner;
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
        $this->registerParsing();
        $this->registerPreprocessing();
        $this->registerIngestion();
        $this->registerChunking();
        $this->registerEmbedding();

        $this->app->singleton(RagEngine::class, fn ($app) => new RagEngine(
            $app->make(EmbedderManager::class),
            $app->make(VectorStoreManager::class),
            $app->make(RerankerManager::class),
            $app->make(KmsManager::class),
            $app->make(TokenizerManager::class),
            $app->make(LlmManager::class),
            $app->make(EnvelopeEncrypter::class),
            $app->make(TenantContext::class),
            $app->make(SourceFactory::class),
            $app->make(Ingestor::class),
            $app->make(ParserManager::class),
            $app->make(ChunkingService::class),
            $app->make(EmbeddingService::class),
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

    private function registerParsing(): void
    {
        $this->app->singleton(ParserManager::class, function (): ParserManager {
            // Registered last-wins, so list specific parsers; PDF only if usable.
            $parsers = [
                new PlainTextParser,
                new MarkdownParser,
                new HtmlParser,
                new XmlParser,
                new CsvParser,
                new JsonParser,
                new DocxParser,
            ];

            if (PdfParser::isAvailable()) {
                $parsers[] = new PdfParser;
            }

            return new ParserManager($parsers);
        });
    }

    private function registerPreprocessing(): void
    {
        $this->app->bind(PreprocessingPipeline::class, function ($app): PreprocessingPipeline {
            $config = $app->make('config');
            $strategy = (string) $config->get('rag-engine.security.pii_strategy', 'mask');
            $piiEnabled = (bool) $config->get('rag-engine.security.pii_redaction_enabled', true);

            $available = [
                'text-cleaner' => new TextCleaner,
                'language-detector' => new LanguageDetector,
                'pii-redactor' => new PiiRedactor($strategy),
            ];

            $pipeline = new PreprocessingPipeline;

            foreach ((array) $config->get('rag-engine.preprocessing.stages', []) as $name) {
                if ($name === 'pii-redactor' && ! $piiEnabled) {
                    continue;
                }

                if (isset($available[$name])) {
                    $pipeline->pipe($available[$name]);
                }
            }

            return $pipeline;
        });
    }

    private function registerIngestion(): void
    {
        $this->app->singleton(SourceFactory::class, fn ($app) => new SourceFactory(
            $app->make(Factory::class),
        ));

        $this->app->singleton(Ingestor::class, fn ($app) => new Ingestor(
            $app->make(TenantContext::class),
            $app->make(EnvelopeEncrypter::class),
            $app->make('config'),
        ));
    }

    private function registerChunking(): void
    {
        $this->app->singleton(ChunkerManager::class, fn ($app) => new ChunkerManager($app));
        $this->app->singleton(ContextualHeaderEnricher::class, fn () => new ContextualHeaderEnricher);

        $this->app->singleton(ChunkingService::class, fn ($app) => new ChunkingService(
            $app->make(ChunkerManager::class),
            $app->make(ContextualHeaderEnricher::class),
            $app->make('config'),
        ));
    }

    private function registerEmbedding(): void
    {
        $this->app->singleton(UsageRecorder::class, fn ($app) => new UsageRecorder(
            $app->make(TenantContext::class),
        ));

        $this->app->singleton(EmbeddingService::class, fn ($app) => new EmbeddingService(
            $app->make(EmbedderManager::class),
            $app->make(UsageRecorder::class),
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
