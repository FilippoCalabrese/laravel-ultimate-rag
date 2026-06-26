<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\SearchHit;

/**
 * Re-orders retrieved hits by relevance to the query (FR-RR-01), typically with
 * a cross-encoder. Pluggable driver, EU option available.
 */
interface Reranker
{
    /**
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit> Re-scored and re-sorted, truncated to $topK.
     */
    public function rerank(string $query, array $hits, int $topK): array;

    public function name(): string;
}
