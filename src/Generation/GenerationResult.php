<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Illuminate\Contracts\Support\Arrayable;
use Sellinnate\RagEngine\Data\SearchHit;

/**
 * The answer plus its source attribution (FR-GE-03).
 *
 * @implements Arrayable<string, mixed>
 */
final class GenerationResult implements Arrayable
{
    /**
     * @param  list<array{index: int, document_id: ?string, chunk_id: ?string}>  $citations
     * @param  list<SearchHit>  $sources
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations,
        public readonly array $sources,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'citations' => $this->citations,
            'sources' => array_map(static fn (SearchHit $h): array => $h->toArray(), $this->sources),
        ];
    }
}
