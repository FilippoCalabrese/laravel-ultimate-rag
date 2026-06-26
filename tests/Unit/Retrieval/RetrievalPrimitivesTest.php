<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Retrieval\KeywordScorer;
use Sellinnate\RagEngine\Retrieval\Mmr;
use Sellinnate\RagEngine\Retrieval\Rrf;

it('Rrf fuses two rankings favouring items ranked high in both (FR-RT-03)', function () {
    $vector = [new SearchHit('a', 0.9), new SearchHit('b', 0.8), new SearchHit('c', 0.7)];
    $keyword = [new SearchHit('c', 5.0), new SearchHit('a', 4.0), new SearchHit('z', 3.0)];

    $fused = (new Rrf)->fuse([$vector, $keyword], 3);

    // 'a' is high in both → should rank first; all unique ids preserved.
    expect($fused[0]->id)->toBe('a')
        ->and(array_map(fn ($h) => $h->id, $fused))->toContain('c')->toContain('a');
});

it('Rrf truncates to top-k', function () {
    $r = [new SearchHit('a', 1), new SearchHit('b', 1), new SearchHit('c', 1)];

    expect((new Rrf)->fuse([$r], 2))->toHaveCount(2);
});

it('Mmr diversifies away from near-duplicate vectors (FR-RT-05)', function () {
    $query = [1.0, 0.0, 0.0];
    $candidates = [
        ['hit' => new SearchHit('a', 0.99), 'vector' => [1.0, 0.0, 0.0]],
        ['hit' => new SearchHit('a2', 0.98), 'vector' => [0.99, 0.01, 0.0]], // near-duplicate of a
        ['hit' => new SearchHit('b', 0.7), 'vector' => [0.0, 1.0, 0.0]],     // diverse
    ];

    $selected = (new Mmr)->select($query, $candidates, 2, 0.3); // diversity-weighted
    $ids = array_map(fn ($h) => $h->id, $selected);

    expect($selected)->toHaveCount(2)
        ->and($ids[0])->toBe('a')
        ->and($ids)->toContain('b'); // picks the diverse one over the near-duplicate
});

it('Mmr with lambda=1 ignores diversity (pure relevance)', function () {
    $query = [1.0, 0.0, 0.0];
    $candidates = [
        ['hit' => new SearchHit('a', 0.9), 'vector' => [1.0, 0.0, 0.0]],
        ['hit' => new SearchHit('a2', 0.9), 'vector' => [0.99, 0.01, 0.0]],
        ['hit' => new SearchHit('b', 0.9), 'vector' => [0.0, 1.0, 0.0]],
    ];

    $ids = array_map(fn ($h) => $h->id, (new Mmr)->select($query, $candidates, 2, 1.0));

    expect($ids)->toBe(['a', 'a2']); // two most relevant, diversity ignored
});

it('KeywordScorer ranks by BM25 term overlap (FR-RT-03)', function () {
    $candidates = [
        new SearchHit('a', 0.0, 'the cat sat on the mat'),
        new SearchHit('b', 0.0, 'machine learning vectors and embeddings'),
        new SearchHit('c', 0.0, 'a dog ran in the park'),
    ];

    $ranked = (new KeywordScorer)->rank('machine learning embeddings', $candidates, 10);

    expect($ranked[0]->id)->toBe('b')
        ->and($ranked[0]->score)->toBeGreaterThan(0.0);
});

it('KeywordScorer returns nothing for an empty query or no matches', function () {
    $candidates = [new SearchHit('a', 0.0, 'hello world')];

    expect((new KeywordScorer)->rank('', $candidates, 10))->toBe([])
        ->and((new KeywordScorer)->rank('zzz qqq', $candidates, 10))->toBe([]);
});
