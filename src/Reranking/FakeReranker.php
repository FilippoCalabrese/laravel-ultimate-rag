<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Data\SearchHit;

/**
 * Deterministic reranker for tests: re-scores hits by lexical term overlap with
 * the query, so reranking demonstrably changes ordering without a network call.
 */
final class FakeReranker implements Reranker
{
    public function rerank(string $query, array $hits, int $topK): array
    {
        $terms = $this->terms($query);

        $rescored = array_map(function (SearchHit $hit) use ($terms): SearchHit {
            $hitTerms = $this->terms($hit->content);
            $overlap = count(array_intersect($terms, $hitTerms));
            $score = count($terms) > 0 ? $overlap / count($terms) : 0.0;

            return $hit->withScore($score);
        }, $hits);

        usort($rescored, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($rescored, 0, max(0, $topK));
    }

    public function name(): string
    {
        return 'fake';
    }

    /**
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($words));
    }
}
