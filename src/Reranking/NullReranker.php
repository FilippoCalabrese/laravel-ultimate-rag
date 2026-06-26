<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

use Sellinnate\RagEngine\Contracts\Reranker;

/**
 * Pass-through reranker: preserves the input order, just truncates to top-k.
 * The default when no rerank provider is configured.
 */
final class NullReranker implements Reranker
{
    public function rerank(string $query, array $hits, int $topK): array
    {
        return array_slice($hits, 0, max(0, $topK));
    }

    public function name(): string
    {
        return 'null';
    }
}
