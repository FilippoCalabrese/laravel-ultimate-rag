<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

/**
 * Jina AI reranker cross-encoder (FR-RR-01, EU). Models:
 * `jina-reranker-v2-base-multilingual`. Endpoint `/v1/rerank`, Bearer auth.
 */
final class JinaReranker extends HttpReranker
{
    public function name(): string
    {
        return 'jina';
    }

    protected function endpoint(): string
    {
        return '/v1/rerank';
    }
}
