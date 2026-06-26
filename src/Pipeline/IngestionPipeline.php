<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Pipeline;

use Sellinnate\RagEngine\Chunking\ChunkingService;
use Sellinnate\RagEngine\Events\DocumentChunked;
use Sellinnate\RagEngine\Events\IngestionFailed;
use Sellinnate\RagEngine\Indexing\Indexer;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Preprocessing\PreprocessingPipeline;
use Throwable;

/**
 * Orchestrates the per-document ingestion pipeline (FR-OR-01/02): decrypt →
 * parse → preprocess (PII redaction) → chunk → embed → index, tracking state
 * transitions on the Document (pending → parsing → chunking → embedding →
 * indexed → failed).
 */
final class IngestionPipeline
{
    public const STATES = ['pending', 'parsing', 'chunking', 'embedding', 'indexed', 'failed'];

    public function __construct(
        private readonly Ingestor $ingestor,
        private readonly ParserManager $parsers,
        private readonly PreprocessingPipeline $preprocessing,
        private readonly ChunkingService $chunking,
        private readonly Indexer $indexer,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function process(Document $document, array $options = []): int
    {
        try {
            $this->transition($document, 'parsing');

            $content = $this->ingestor->content($document);
            $parsed = $this->parsers->parse($content, (string) ($document->mime ?? 'text/plain'), [
                'filename' => $document->metadata['filename'] ?? null,
            ]);

            // Preprocess: clean + detect language + redact PII before indexing.
            $parsed = $this->preprocessing->process($parsed);

            $this->transition($document, 'chunking');
            if ($parsed->language !== null) {
                $document->forceFill(['language' => $parsed->language])->save();
            }

            $chunks = $this->chunking->chunk($parsed, $options);
            event(new DocumentChunked((string) $document->id, $document->tenant_id, count($chunks)));

            $this->transition($document, 'embedding');

            // Indexer sets status to 'indexed' and emits DocumentIndexed.
            return $this->indexer->index($document, $chunks, $options);
        } catch (Throwable $e) {
            $document->forceFill(['status' => 'failed'])->save();
            event(new IngestionFailed((string) $document->id, $document->tenant_id, $e->getMessage()));

            throw $e;
        }
    }

    private function transition(Document $document, string $state): void
    {
        $document->forceFill(['status' => $state])->save();
    }
}
