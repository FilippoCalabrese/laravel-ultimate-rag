<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Data\Usage;

it('DocumentSection exposes its fields and toArray', function () {
    $section = new DocumentSection('heading', 'Chapter 1', level: 1, page: 3, metadata: ['x' => 1]);

    expect($section->type)->toBe('heading')
        ->and($section->page)->toBe(3)
        ->and($section->toArray())->toBe([
            'type' => 'heading',
            'content' => 'Chapter 1',
            'level' => 1,
            'page' => 3,
            'metadata' => ['x' => 1],
        ]);
});

it('SearchHit is immutable and serializable (FR-RT-06)', function () {
    $hit = new SearchHit('id', 0.5, 'body', ['k' => 'v'], 'doc', 'chunk');
    $rescored = $hit->withScore(0.9)->withContent('new body');

    expect($hit->score)->toBe(0.5)
        ->and($rescored->score)->toBe(0.9)
        ->and($rescored->content)->toBe('new body')
        ->and($hit->toArray())->toBe([
            'id' => 'id',
            'score' => 0.5,
            'content' => 'body',
            'metadata' => ['k' => 'v'],
            'document_id' => 'doc',
            'chunk_id' => 'chunk',
        ]);
});

it('Usage::zero is a neutral element and toArray works', function () {
    $zero = Usage::zero('USD');

    expect($zero->tokens)->toBe(0)
        ->and($zero->currency)->toBe('USD')
        ->and($zero->plus(new Usage(5, 1.0, 'USD'))->tokens)->toBe(5)
        ->and((new Usage(2, 0.1))->toArray())->toBe(['tokens' => 2, 'cost' => 0.1, 'currency' => 'EUR']);
});
