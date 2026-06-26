<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Chunking\FixedSizeChunker;
use Sellinnate\RagEngine\Chunking\MarkdownChunker;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Chunking\SentenceChunker;
use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Contracts\Tokenizer;

/**
 * Resolves chunking strategies (FR-CH-10, pluggable driver).
 *
 * @extends DriverManager<Chunker>
 */
final class ChunkerManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'chunkers';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.chunker', 'recursive');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFixedDriver(array $config): Chunker
    {
        return new FixedSizeChunker($this->app->make(Tokenizer::class));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createRecursiveDriver(array $config): Chunker
    {
        return new RecursiveCharacterChunker($this->app->make(Tokenizer::class));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createSentenceDriver(array $config): Chunker
    {
        return new SentenceChunker($this->app->make(Tokenizer::class));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMarkdownDriver(array $config): Chunker
    {
        return new MarkdownChunker($this->app->make(Tokenizer::class));
    }
}
