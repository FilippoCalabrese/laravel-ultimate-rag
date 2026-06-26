<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Contextual chunk headers (FR-CH-08): enriches each chunk with a header that
 * situates it (document title, section heading) so the embedded text carries
 * document/section context, improving retrieval of otherwise ambiguous chunks.
 */
final class ContextualHeaderEnricher
{
    /**
     * @param  list<TextChunk>  $chunks
     * @return list<TextChunk>
     */
    public function enrich(array $chunks, ParsedDocument $document): array
    {
        $title = $this->documentTitle($document);

        return array_map(function (TextChunk $chunk) use ($title): TextChunk {
            $parts = [];

            if ($title !== null) {
                $parts[] = "Document: {$title}";
            }

            $heading = $chunk->metadata['heading'] ?? null;
            if (is_string($heading) && $heading !== '') {
                $parts[] = "Section: {$heading}";
            }

            return $parts === [] ? $chunk : $chunk->withContextHeader(implode(' > ', $parts));
        }, $chunks);
    }

    private function documentTitle(ParsedDocument $document): ?string
    {
        $title = $document->metadata['title'] ?? $document->metadata['filename'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
    }
}
