<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use Sellinnate\RagEngine\Data\SearchHit;

/**
 * Reciprocal Rank Fusion (FR-RT-03) for hybrid search: fuses several ranked
 * result lists into one, scoring each item by sum of 1/(k + rank) across the
 * lists it appears in. Robust to differing score scales (vector vs keyword).
 */
final class Rrf
{
    public function __construct(private readonly int $k = 60) {}

    /**
     * @param  list<list<SearchHit>>  $rankings  Each list ordered best-first.
     * @return list<SearchHit> Fused, ordered best-first, with the fused RRF score.
     */
    public function fuse(array $rankings, int $topK): array
    {
        /** @var array<string, float> $scores */
        $scores = [];
        /** @var array<string, SearchHit> $hits */
        $hits = [];

        foreach ($rankings as $ranking) {
            // Explicit rank counter — never trust the array key (a non-list input,
            // e.g. from array_filter without reindex, would corrupt RRF scores).
            $rank = 0;
            foreach ($ranking as $hit) {
                $scores[$hit->id] = ($scores[$hit->id] ?? 0.0) + 1.0 / ($this->k + $rank + 1);
                $hits[$hit->id] ??= $hit;
                $rank++;
            }
        }

        arsort($scores);

        $fused = [];
        foreach (array_keys($scores) as $id) {
            $fused[] = $hits[$id]->withScore($scores[$id]);
        }

        return array_slice($fused, 0, max(0, $topK));
    }
}
