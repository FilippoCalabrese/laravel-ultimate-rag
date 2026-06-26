<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Illuminate\Contracts\Config\Repository as Config;
use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Managers\ChunkerManager;

/**
 * Orchestrates chunking: resolves the strategy, optionally wraps it in
 * parent-child (FR-CH-07), runs it, and applies contextual headers (FR-CH-08).
 * The single entrypoint the ingestion pipeline calls.
 */
final class ChunkingService
{
    public function __construct(
        private readonly ChunkerManager $chunkers,
        private readonly ContextualHeaderEnricher $enricher,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return list<TextChunk>
     */
    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $strategy = (string) ($options['strategy'] ?? $this->config->get('rag-engine.defaults.chunker', 'recursive'));
        $parentChild = (bool) ($options['parent_child'] ?? $this->config->get('rag-engine.chunking.parent_child', false));

        $options = [
            'size' => (int) $this->config->get('rag-engine.chunking.chunk_size', 1000),
            'overlap' => (int) $this->config->get('rag-engine.chunking.chunk_overlap', 200),
            ...$options,
        ];

        $chunker = $this->resolveChunker($strategy, $parentChild);
        $chunks = $chunker->chunk($document, $options);

        if ($this->config->get('rag-engine.chunking.contextual_headers', true)) {
            $chunks = $this->enricher->enrich($chunks, $document);
        }

        return $chunks;
    }

    private function resolveChunker(string $strategy, bool $parentChild): Chunker
    {
        $base = $this->chunkers->driver($strategy);

        if (! $parentChild) {
            return $base;
        }

        // Children use the selected strategy; parents use a coarse recursive split.
        return new ParentChildChunker(
            parentChunker: $this->chunkers->driver('recursive'),
            childChunker: $base,
        );
    }
}
