<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Shared chunker behaviour: token counting (FR-CH-06) and metadata propagation
 * from document to chunk (FR-CH-09).
 */
abstract class AbstractChunker implements Chunker
{
    public function __construct(protected readonly Tokenizer $tokenizer) {}

    /**
     * Build a TextChunk with token count and propagated document metadata.
     *
     * @param  array<string, mixed>  $documentMetadata
     * @param  array<string, mixed>  $extra
     */
    protected function makeChunk(
        string $content,
        int $index,
        int $offset,
        array $documentMetadata = [],
        array $extra = [],
    ): TextChunk {
        return new TextChunk(
            content: $content,
            index: $index,
            offset: $offset,
            tokenCount: $this->tokenizer->count($content),
            metadata: [
                ...$this->inheritableMetadata($documentMetadata),
                ...$extra,
                'chunk_index' => $index,
                'offset' => $offset,
            ],
        );
    }

    /**
     * Document metadata that should flow down to chunks, minus volatile keys.
     *
     * @param  array<string, mixed>  $documentMetadata
     * @return array<string, mixed>
     */
    protected function inheritableMetadata(array $documentMetadata): array
    {
        // Don't propagate per-document bookkeeping or large nested trees.
        $excluded = ['pii_tokens', 'pii_redactions', 'json', 'rows', 'provenance'];

        return array_diff_key($documentMetadata, array_flip($excluded));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function option(array $options, string $key, mixed $default): mixed
    {
        return $options[$key] ?? $default;
    }

    abstract public function chunk(ParsedDocument $document, array $options = []): array;
}
