<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Evaluation;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Aggregate retrieval-quality metrics over a set of {@see EvaluationCase}s, plus
 * the per-case breakdown. All rates are in [0, 1].
 *
 * - **hitRate** — fraction of queries with ≥1 relevant result in the top-k.
 * - **recall@k** — mean fraction of a query's relevant ids found in the top-k.
 * - **precision@k** — mean (relevant found in top-k) / k.
 * - **MRR** — mean reciprocal rank of the first relevant result.
 *
 * @implements Arrayable<string, mixed>
 */
final class RetrievalReport implements Arrayable
{
    /**
     * @param  list<array<string, mixed>>  $cases
     */
    public function __construct(
        public readonly int $count,
        public readonly int $k,
        public readonly float $hitRate,
        public readonly float $recallAtK,
        public readonly float $precisionAtK,
        public readonly float $mrr,
        public readonly array $cases,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'k' => $this->k,
            'hit_rate' => $this->hitRate,
            'recall_at_k' => $this->recallAtK,
            'precision_at_k' => $this->precisionAtK,
            'mrr' => $this->mrr,
            'cases' => $this->cases,
        ];
    }
}
