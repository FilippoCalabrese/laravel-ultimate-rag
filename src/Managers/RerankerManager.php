<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Reranking\CohereReranker;
use Sellinnate\RagEngine\Reranking\FakeReranker;
use Sellinnate\RagEngine\Reranking\JinaReranker;
use Sellinnate\RagEngine\Reranking\NullReranker;

/**
 * Resolves rerankers (FR-RR-01). Ships the no-op `null`, deterministic `fake`,
 * and real cross-encoder drivers for Cohere and Jina.
 *
 * @extends DriverManager<Reranker>
 */
final class RerankerManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'rerankers';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.reranker', 'null');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createNullDriver(array $config): Reranker
    {
        return new NullReranker;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFakeDriver(array $config): Reranker
    {
        return new FakeReranker;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createCohereDriver(array $config): Reranker
    {
        return new CohereReranker(
            $this->app->make(HttpFactory::class),
            (string) ($config['model'] ?? 'rerank-v3.5'),
            isset($config['api_key']) ? (string) $config['api_key'] : null,
            (string) ($config['base_url'] ?? 'https://api.cohere.com'),
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createJinaDriver(array $config): Reranker
    {
        return new JinaReranker(
            $this->app->make(HttpFactory::class),
            (string) ($config['model'] ?? 'jina-reranker-v2-base-multilingual'),
            isset($config['api_key']) ? (string) $config['api_key'] : null,
            (string) ($config['base_url'] ?? 'https://api.jina.ai'),
            $config,
        );
    }
}
