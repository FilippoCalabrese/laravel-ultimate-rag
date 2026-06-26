<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * An indexable fragment of a document.
 *
 * Implements FR-CH-07 (parent-child) via $parentIndex and FR-CH-09 (metadata
 * propagation) via $metadata carrying page/offset/index. $tokenCount supports
 * FR-CH-06 token-aware budgeting.
 *
 * @implements Arrayable<string, mixed>
 */
final class TextChunk implements Arrayable
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly int $index,
        public readonly int $offset = 0,
        public readonly ?int $tokenCount = null,
        public readonly ?int $parentIndex = null,
        public readonly array $metadata = [],
        public readonly ?string $contextHeader = null,
    ) {}

    /**
     * The text actually sent to the embedder: contextual header (FR-CH-08)
     * prepended to the raw content when present.
     */
    public function embeddableText(): string
    {
        return $this->contextHeader !== null && $this->contextHeader !== ''
            ? $this->contextHeader."\n\n".$this->content
            : $this->content;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->content,
            $this->index,
            $this->offset,
            $this->tokenCount,
            $this->parentIndex,
            [...$this->metadata, ...$metadata],
            $this->contextHeader,
        );
    }

    public function withTokenCount(int $tokenCount): self
    {
        return new self(
            $this->content,
            $this->index,
            $this->offset,
            $tokenCount,
            $this->parentIndex,
            $this->metadata,
            $this->contextHeader,
        );
    }

    public function withContextHeader(?string $header): self
    {
        return new self(
            $this->content,
            $this->index,
            $this->offset,
            $this->tokenCount,
            $this->parentIndex,
            $this->metadata,
            $header,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'index' => $this->index,
            'offset' => $this->offset,
            'token_count' => $this->tokenCount,
            'parent_index' => $this->parentIndex,
            'metadata' => $this->metadata,
            'context_header' => $this->contextHeader,
        ];
    }
}
