<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A single scored retrieval result with full provenance (FR-RT-06).
 *
 * @implements Arrayable<string, mixed>
 */
final class SearchHit implements Arrayable
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly float $score,
        public readonly string $content = '',
        public readonly array $metadata = [],
        public readonly ?string $documentId = null,
        public readonly ?string $chunkId = null,
    ) {}

    public function withScore(float $score): self
    {
        return new self($this->id, $score, $this->content, $this->metadata, $this->documentId, $this->chunkId);
    }

    public function withContent(string $content): self
    {
        return new self($this->id, $this->score, $content, $this->metadata, $this->documentId, $this->chunkId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'document_id' => $this->documentId,
            'chunk_id' => $this->chunkId,
        ];
    }
}
