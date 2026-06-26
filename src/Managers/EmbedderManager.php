<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;

/**
 * Resolves embedding providers (FR-EM, FR-EV-04).
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
}
