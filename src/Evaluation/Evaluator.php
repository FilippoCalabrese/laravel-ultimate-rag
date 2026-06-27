<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Evaluation;

use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Retrieval\Retriever;
use Sellinnate\RagEngine\Retrieval\SearchRequest;

/**
 * Measures retrieval quality over labelled cases (FR-EV) so you can tune chunking,
 * embedders, hybrid/rerank/MMR with numbers instead of guesswork. Runs each query
 * through the real retrieval pipeline (tenant-scoped) and computes hit-rate,
 * recall@k, precision@k and MRR.
 */
final class Evaluator
{
    public function __construct(
        private readonly Retriever $retriever,
    ) {}

    /**
     * @param  iterable<EvaluationCase>  $cases
     * @param  array<string, mixed>  $options  hybrid, rerank, reranker, mmr, threshold, filters, embedder, store, namespace
     */
    public function evaluateRetrieval(iterable $cases, int $k = 5, array $options = []): RetrievalReport
    {
        $n = 0;
        $sumRecall = 0.0;
        $sumPrecision = 0.0;
        $sumRr = 0.0;
        $hits = 0;
        $perCase = [];

        foreach ($cases as $case) {
            $n++;
            $results = $this->retriever->retrieve($this->request($case->query, $k, $options));

            $relevant = array_values(array_unique($case->relevant));
            $relevantCount = count($relevant);

            $foundIds = [];
            $reciprocalRank = 0.0;
            $rank = 0;

            foreach ($results as $hit) {
                $rank++;
                $matched = $this->matchedRelevantIds($hit, $relevant);

                if ($matched !== []) {
                    $foundIds = [...$foundIds, ...$matched];
                    if ($reciprocalRank === 0.0) {
                        $reciprocalRank = 1.0 / $rank;
                    }
                }
            }

            $found = count(array_unique($foundIds));
            $recall = $relevantCount > 0 ? $found / $relevantCount : 0.0;
            $precision = $k > 0 ? $found / $k : 0.0;
            $hit = $reciprocalRank > 0.0;

            $sumRecall += $recall;
            $sumPrecision += $precision;
            $sumRr += $reciprocalRank;
            $hits += $hit ? 1 : 0;

            $perCase[] = [
                'query' => $case->query,
                'hit' => $hit,
                'recall' => $recall,
                'precision' => $precision,
                'reciprocal_rank' => $reciprocalRank,
                'relevant' => $relevantCount,
                'retrieved' => count($results),
            ];
        }

        $divisor = max(1, $n);

        return new RetrievalReport(
            count: $n,
            k: $k,
            hitRate: $hits / $divisor,
            recallAtK: $sumRecall / $divisor,
            precisionAtK: $sumPrecision / $divisor,
            mrr: $sumRr / $divisor,
            cases: $perCase,
        );
    }

    /**
     * @param  list<string>  $relevant
     * @return list<string>
     */
    private function matchedRelevantIds(SearchHit $hit, array $relevant): array
    {
        $candidates = [$hit->id];
        if ($hit->documentId !== null) {
            $candidates[] = $hit->documentId;
        }

        return array_values(array_intersect($relevant, $candidates));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(string $query, int $k, array $options): SearchRequest
    {
        return new SearchRequest(
            text: $query,
            topK: $k,
            scoreThreshold: isset($options['threshold']) ? (float) $options['threshold'] : null,
            filters: is_array($options['filters'] ?? null) ? $options['filters'] : [],
            namespace: is_string($options['namespace'] ?? null) ? $options['namespace'] : 'documents',
            hybrid: (bool) ($options['hybrid'] ?? false),
            mmr: (bool) ($options['mmr'] ?? false),
            rerank: (bool) ($options['rerank'] ?? false),
            rerankerName: is_string($options['reranker'] ?? null) ? $options['reranker'] : null,
            embedder: is_string($options['embedder'] ?? null) ? $options['embedder'] : null,
            store: is_string($options['store'] ?? null) ? $options['store'] : null,
        );
    }
}
