<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

/**
 * Immutable description of a retrieval request, produced by {@see SearchBuilder}
 * and consumed by {@see Retriever}. Covers top-k, threshold, filters, hybrid,
 * MMR, reranking, parent expansion and context budget.
 */
final class SearchRequest
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $text,
        public readonly int $topK = 10,
        public readonly ?float $scoreThreshold = null,
        public readonly array $filters = [],
        public readonly string $namespace = 'documents',
        public readonly bool $hybrid = false,
        public readonly bool $mmr = false,
        public readonly float $mmrLambda = 0.5,
        public readonly bool $rerank = false,
        public readonly ?string $rerankerName = null,
        public readonly bool $expandParents = false,
        public readonly ?int $contextBudgetTokens = null,
        public readonly bool $dedup = true,
        public readonly ?int $fetchK = null,
        public readonly ?string $embedder = null,
        public readonly ?string $store = null,
        public readonly bool $expandQueries = false,
        public readonly int $queryVariations = 3,
        public readonly ?string $llm = null,
    ) {}

    /**
     * Candidate pool size: larger when rerank/MMR/hybrid/multi-query need headroom.
     */
    public function effectiveFetchK(): int
    {
        if ($this->fetchK !== null) {
            return max($this->fetchK, $this->topK);
        }

        return ($this->rerank || $this->mmr || $this->hybrid || $this->expandQueries)
            ? max($this->topK * 4, $this->topK)
            : $this->topK;
    }
}
