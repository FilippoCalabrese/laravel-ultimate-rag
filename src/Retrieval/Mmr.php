<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Support\Vectors;

/**
 * Maximal Marginal Relevance (FR-RT-05): diversifies results by trading off
 * relevance to the query against similarity to already-selected results.
 *
 * score = λ · sim(query, doc) − (1 − λ) · max sim(doc, selected)
 */
final class Mmr
{
    /**
     * @param  list<float>  $queryVector
     * @param  list<array{hit: SearchHit, vector: list<float>}>  $candidates
     * @return list<SearchHit>
     */
    public function select(array $queryVector, array $candidates, int $topK, float $lambda = 0.5): array
    {
        $remaining = $candidates;
        $selected = [];

        while ($remaining !== [] && count($selected) < $topK) {
            $bestIndex = null;
            $bestScore = -INF;

            foreach ($remaining as $i => $candidate) {
                $relevance = Vectors::cosine($queryVector, $candidate['vector']);

                $redundancy = 0.0;
                foreach ($selected as $chosen) {
                    $redundancy = max($redundancy, Vectors::cosine($candidate['vector'], $chosen['vector']));
                }

                $score = $lambda * $relevance - (1 - $lambda) * $redundancy;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $i;
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $selected[] = $remaining[$bestIndex];
            array_splice($remaining, $bestIndex, 1);
        }

        return array_map(static fn (array $c): SearchHit => $c['hit'], $selected);
    }
}
