<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;
use Sellinnate\RagEngine\VectorStore\QdrantStore;

/**
 * Resolves vector store backends (FR-VS, decision 6.2).
 *
 * @extends DriverManager<VectorStore>
 */
final class VectorStoreManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'vector_stores';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.vector_store', 'memory');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMemoryDriver(array $config): VectorStore
    {
        return new InMemoryVectorStore;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createQdrantDriver(array $config): VectorStore
    {
        return new QdrantStore(
            http: $this->app->make(HttpFactory::class),
            host: (string) ($config['host'] ?? 'http://localhost:6333'),
            apiKey: $config['api_key'] ?? null,
        );
    }
}
