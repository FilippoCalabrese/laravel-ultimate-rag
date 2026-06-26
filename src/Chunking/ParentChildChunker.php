<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Contracts\Chunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Parent-child / small-to-big chunking (FR-CH-07).
 *
 * Large parent chunks give context; small child chunks are what gets embedded
 * and retrieved. The result is a flat list with parents first (flagged
 * `is_parent`), then children that reference their parent by index (FR-RT-07) —
 * parent text is stored ONCE, never duplicated into each child.
 *
 * The embedding/persistence layer embeds only children (`is_parent` false) and
 * links them to parents via `parent_index` / `parent_chunk_id`.
 */
final class ParentChildChunker implements Chunker
{
    public function __construct(
        private readonly Chunker $parentChunker,
        private readonly Chunker $childChunker,
    ) {}

    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $parentOptions = [
            'size' => (int) ($options['parent_size'] ?? 2000),
            'overlap' => (int) ($options['parent_overlap'] ?? 0),
        ];
        $childOptions = [
            'size' => (int) ($options['child_size'] ?? 400),
            'overlap' => (int) ($options['child_overlap'] ?? 50),
        ];

        $parents = $this->parentChunker->chunk($document, $parentOptions);

        $result = [];
        $parentPosition = [];
        $index = 0;

        // Parents first, stored once.
        foreach ($parents as $localIndex => $parent) {
            $parentPosition[$localIndex] = $index;

            $result[] = new TextChunk(
                content: $parent->content,
                index: $index,
                offset: $parent->offset,
                tokenCount: $parent->tokenCount,
                metadata: [...$parent->metadata, 'is_parent' => true],
                contextHeader: $parent->contextHeader,
            );
            $index++;
        }

        // Children reference their parent by position; no text duplication.
        foreach ($parents as $localIndex => $parent) {
            $parentDoc = new ParsedDocument($parent->content, $document->mimeType, metadata: $document->metadata);

            foreach ($this->childChunker->chunk($parentDoc, $childOptions) as $child) {
                $result[] = new TextChunk(
                    content: $child->content,
                    index: $index++,
                    offset: $parent->offset + $child->offset,
                    tokenCount: $child->tokenCount,
                    parentIndex: $parentPosition[$localIndex],
                    metadata: [
                        ...$child->metadata,
                        'is_parent' => false,
                        'parent_index' => $parentPosition[$localIndex],
                    ],
                    contextHeader: $child->contextHeader,
                );
            }
        }

        return $result;
    }

    public function name(): string
    {
        return 'parent-child';
    }
}
