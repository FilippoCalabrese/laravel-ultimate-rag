<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Sellinnate\RagEngine\Contracts\VectorStore;

/**
 * Immutable description of a retrieval request.
 *
 * Carries everything a {@see VectorStore} or the
 * retrieval pipeline needs: top-k (FR-RT-01), score threshold (FR-RT-02),
 * metadata filters (FR-RT-04) and the namespace/tenant scope (FR-VS-09).
 */
final class RetrievalQuery
{
    /**
     * @param  array<string, mixed>  $filters  Metadata filters (tenant, tags, date, source).
     */
    public function __construct(
        public readonly string $text,
        public readonly int $topK = 10,
        public readonly ?float $scoreThreshold = null,
        public readonly array $filters = [],
        public readonly ?string $namespace = null,
        public readonly ?string $tenantId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function withFilters(array $filters): self
    {
        return new self(
            $this->text,
            $this->topK,
            $this->scoreThreshold,
            [...$this->filters, ...$filters],
            $this->namespace,
            $this->tenantId,
        );
    }

    public function withTopK(int $topK): self
    {
        return new self($this->text, $topK, $this->scoreThreshold, $this->filters, $this->namespace, $this->tenantId);
    }
}
