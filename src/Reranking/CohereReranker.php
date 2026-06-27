<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

/**
 * Cohere Rerank cross-encoder (FR-RR-01). Models: `rerank-v3.5`,
 * `rerank-multilingual-v3.0`. Endpoint `/v2/rerank`, Bearer auth.
 */
final class CohereReranker extends HttpReranker
{
    public function name(): string
    {
        return 'cohere';
    }

    protected function endpoint(): string
    {
        return '/v2/rerank';
    }
}
