<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Embedding\MistralEmbedder;
use Sellinnate\RagEngine\Embedding\OllamaEmbedder;
use Sellinnate\RagEngine\Embedding\RetryingEmbedder;

/**
 * Resolves embedding providers (FR-EM, FR-EV-04). Real providers are wrapped
 * with retry (FR-EM-06) and caching (FR-EM-05) decorators per config.
 *
 * @extends DriverManager<Embedder>
 */
final class EmbedderManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'embedders';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.embedder', 'fake');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFakeDriver(array $config): Embedder
    {
        return new FakeEmbedder(
            dimensions: (int) ($config['dimensions'] ?? 8),
            model: (string) ($config['model'] ?? 'fake-embed-v1'),
            tokenizer: $this->app->make(Tokenizer::class),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMistralDriver(array $config, string $name): Embedder
    {
        return $this->decorate(new MistralEmbedder(
            http: $this->app->make(HttpFactory::class),
            model: (string) ($config['model'] ?? 'mistral-embed'),
            dimensions: (int) ($config['dimensions'] ?? 1024),
            baseUrl: (string) ($config['base_url'] ?? 'https://api.mistral.ai/v1'),
            apiKey: $config['api_key'] ?? null,
            costPer1kTokens: (float) ($config['cost_per_1k'] ?? 0.0),
        ), $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOllamaDriver(array $config, string $name): Embedder
    {
        return $this->decorate(new OllamaEmbedder(
            http: $this->app->make(HttpFactory::class),
            model: (string) ($config['model'] ?? 'nomic-embed-text'),
            dimensions: (int) ($config['dimensions'] ?? 768),
            baseUrl: (string) ($config['base_url'] ?? 'http://localhost:11434'),
            apiKey: $config['api_key'] ?? null,
            costPer1kTokens: (float) ($config['cost_per_1k'] ?? 0.0),
        ), $config, $name);
    }

    /**
     * Wrap a provider with retry then caching (cache outermost so hits skip retry too).
     *
     * @param  array<string, mixed>  $config
     */
    private function decorate(Embedder $embedder, array $config, string $name): Embedder
    {
        if (($config['retries'] ?? true) !== false) {
            $embedder = new RetryingEmbedder(
                $embedder,
                maxAttempts: (int) ($config['max_attempts'] ?? 3),
            );
        }

        if (($config['cache'] ?? true) !== false) {
            $embedder = new CachingEmbedder(
                $embedder,
                $this->app->make(Cache::class),
                ttl: (int) ($config['cache_ttl'] ?? 2592000),
                identity: $name,
            );
        }

        return $embedder;
    }
}
