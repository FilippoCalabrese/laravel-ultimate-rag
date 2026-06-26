<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Reranking\FakeReranker;
use Sellinnate\RagEngine\Reranking\NullReranker;

/**
 * Resolves rerankers (FR-RR-01).
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
}
