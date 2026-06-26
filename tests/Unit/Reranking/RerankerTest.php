<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Reranking\FakeReranker;
use Sellinnate\RagEngine\Reranking\NullReranker;

it('NullReranker preserves order and truncates', function () {
    $hits = [
        new SearchHit('a', 0.9, 'apple'),
        new SearchHit('b', 0.8, 'banana'),
        new SearchHit('c', 0.7, 'cherry'),
    ];

    $result = (new NullReranker)->rerank('q', $hits, 2);

    expect($result)->toHaveCount(2)
        ->and($result[0]->id)->toBe('a')
        ->and((new NullReranker)->name())->toBe('null');
});

it('FakeReranker reorders by lexical overlap with the query', function () {
    $hits = [
        new SearchHit('a', 0.5, 'the weather is cold today'),
        new SearchHit('b', 0.9, 'machine learning and vectors'),
    ];

    $result = (new FakeReranker)->rerank('vectors and machine learning', $hits, 10);

    expect($result[0]->id)->toBe('b')
        ->and($result[0]->score)->toBeGreaterThan($result[1]->score)
        ->and((new FakeReranker)->name())->toBe('fake');
});
