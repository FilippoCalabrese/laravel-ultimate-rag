<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Retrieval\Mmr;
use Sellinnate\RagEngine\Retrieval\Rrf;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;

it('InMemory L2 score is positive, higher-is-better and in (0,1] (B-H1)', function () {
    $store = new InMemoryVectorStore;
    $store->createNamespace('n', 3, 'l2');
    $store->upsert('n', [
        new VectorRecord('near', [1.0, 0.0, 0.0]),
        new VectorRecord('far', [5.0, 5.0, 5.0]),
    ]);

    $hits = $store->search('n', [1.0, 0.0, 0.0], new RetrievalQuery('q'));

    expect($hits[0]->id)->toBe('near')
        ->and($hits[0]->score)->toBeGreaterThan($hits[1]->score)
        ->and($hits[0]->score)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($hits[1]->score)->toBeGreaterThan(0.0); // never negative
});

it('Rrf uses positional rank even for non-list inputs (B-M2)', function () {
    $listRanking = [new SearchHit('a', 0.9), new SearchHit('b', 0.8)];
    // A non-list (keys 5, 9) must still be ranked 0,1 positionally.
    $nonList = [5 => new SearchHit('b', 0.8), 9 => new SearchHit('c', 0.7)];

    $fused = (new Rrf)->fuse([$listRanking, $nonList], 10);
    $scores = [];
    foreach ($fused as $h) {
        $scores[$h->id] = $h->score;
    }

    // 'b' is rank 1 in list1 and rank 0 in the non-list (treated positionally,
    // NOT by its array key 5) → 1/(60+2) + 1/(60+1).
    $expectedB = 1 / (60 + 2) + 1 / (60 + 1);
    expect($scores['b'])->toEqualWithDelta($expectedB, 1e-9);
});

it('Mmr clamps lambda outside [0,1] (B-M3)', function () {
    $query = [1.0, 0.0, 0.0];
    $candidates = [
        ['hit' => new SearchHit('a', 0.9), 'vector' => [1.0, 0.0, 0.0]],
        ['hit' => new SearchHit('a2', 0.9), 'vector' => [0.99, 0.01, 0.0]],
        ['hit' => new SearchHit('b', 0.9), 'vector' => [0.0, 1.0, 0.0]],
    ];

    // lambda=5 is clamped to 1.0 (pure relevance) → two most-relevant, not a crash.
    $ids = array_map(fn ($h) => $h->id, (new Mmr)->select($query, $candidates, 2, 5.0));

    expect($ids)->toBe(['a', 'a2']);
});

it('Usage::plus rejects mixing currencies (B-L3)', function () {
    $eur = new Usage(10, 1.0, 'EUR');
    $usd = new Usage(5, 0.5, 'USD');

    expect(fn () => $eur->plus($usd))->toThrow(InvalidArgumentException::class, 'Cannot add usage')
        ->and($eur->plus(Usage::zero('USD'))->tokens)->toBe(10); // zero usage is allowed across currencies
});
