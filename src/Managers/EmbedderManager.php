<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Embedding\AzureOpenAIEmbedder;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\CohereEmbedder;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Embedding\GeminiEmbedder;
use Sellinnate\RagEngine\Embedding\HuggingFaceEmbedder;
use Sellinnate\RagEngine\Embedding\JinaEmbedder;
use Sellinnate\RagEngine\Embedding\MistralEmbedder;
use Sellinnate\RagEngine\Embedding\OllamaEmbedder;
use Sellinnate\RagEngine\Embedding\OpenAIEmbedder;
use Sellinnate\RagEngine\Embedding\RetryingEmbedder;
use Sellinnate\RagEngine\Embedding\VoyageEmbedder;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Resolves embedding providers (FR-EM, FR-EV-04). Ships drivers for OpenAI,
 * Azure OpenAI, Mistral, Jina, Voyage, Cohere, Gemini, Hugging Face and Ollama,
 * plus the deterministic `fake` driver. Real providers are wrapped with retry
 * (FR-EM-06) and caching (FR-EM-05) decorators per config.
 *
 * @extends DriverManager<Embedder>
 */
final class EmbedderManager extends DriverManager
{
    /** Default model/dimensions/base-url per built-in HTTP driver. */
    private const DEFAULTS = [
        'openai' => [OpenAIEmbedder::class, 'text-embedding-3-small', 1536, 'https://api.openai.com/v1'],
        'azure-openai' => [AzureOpenAIEmbedder::class, 'text-embedding-3-small', 1536, 'https://example.openai.azure.com'],
        'mistral' => [MistralEmbedder::class, 'mistral-embed', 1024, 'https://api.mistral.ai/v1'],
        'jina' => [JinaEmbedder::class, 'jina-embeddings-v3', 1024, 'https://api.jina.ai/v1'],
        'voyage' => [VoyageEmbedder::class, 'voyage-3', 1024, 'https://api.voyageai.com/v1'],
        'cohere' => [CohereEmbedder::class, 'embed-multilingual-v3.0', 1024, 'https://api.cohere.com'],
        'gemini' => [GeminiEmbedder::class, 'text-embedding-004', 768, 'https://generativelanguage.googleapis.com'],
        'huggingface' => [HuggingFaceEmbedder::class, 'BAAI/bge-small-en-v1.5', 384, 'https://api-inference.huggingface.co'],
        'ollama' => [OllamaEmbedder::class, 'nomic-embed-text', 768, 'http://localhost:11434'],
    ];

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
    protected function createOpenaiDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('openai', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAzureOpenaiDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('azure-openai', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMistralDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('mistral', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createJinaDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('jina', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createVoyageDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('voyage', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createCohereDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('cohere', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createGeminiDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('gemini', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createHuggingfaceDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('huggingface', $config, $name);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOllamaDriver(array $config, string $name): Embedder
    {
        return $this->buildHttp('ollama', $config, $name);
    }

    /**
     * Build a decorated HTTP embedder from a built-in provider's defaults + config.
     *
     * @param  array<string, mixed>  $config
     */
    private function buildHttp(string $provider, array $config, string $name): Embedder
    {
        [$class, $model, $dimensions, $baseUrl] = self::DEFAULTS[$provider];

        $embedder = new $class(
            http: $this->app->make(HttpFactory::class),
            model: (string) ($config['model'] ?? $model),
            dimensions: (int) ($config['dimensions'] ?? $dimensions),
            baseUrl: (string) ($config['base_url'] ?? $baseUrl),
            apiKey: $config['api_key'] ?? null,
            costPer1kTokens: (float) ($config['cost_per_1k'] ?? 0.0),
            options: (array) ($config['options'] ?? []),
        );

        return $this->decorate($embedder, $config, $name);
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
                tenant: $this->app->make(TenantContext::class),
            );
        }

        return $embedder;
    }
}
