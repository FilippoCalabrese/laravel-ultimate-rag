<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Query;

use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\QueryTransformer;

/**
 * Multi-query expansion (FR-QT-01): asks an LLM for alternative phrasings of the
 * query so retrieval can union their results, improving recall. Requires an LLM
 * (optional dependency, FR-QT-04); with the null LLM it degrades to the original
 * query only.
 */
final class MultiQueryTransformer implements QueryTransformer
{
    public function __construct(
        private readonly Llm $llm,
        private readonly int $variations = 3,
    ) {}

    public function transform(string $query): array
    {
        $prompt = "Generate {$this->variations} alternative search queries that capture the intent of the "
            ."following question, one per line, no numbering:\n\n{$query}";

        $output = $this->llm->generate($prompt);

        $lines = array_filter(array_map('trim', preg_split('/\R/u', $output) ?: []));

        $queries = array_values(array_unique([$query, ...$lines]));

        return array_slice($queries, 0, $this->variations + 1);
    }

    public function name(): string
    {
        return 'multi-query';
    }
}
