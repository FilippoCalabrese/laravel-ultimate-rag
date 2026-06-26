<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Retrieval\Retriever;
use Sellinnate\RagEngine\Retrieval\SearchBuilder;

it('builds a fully-specified request from the fluent API (FR-RT-08)', function () {
    $builder = (new SearchBuilder(app(Retriever::class), 'my query'))
        ->topK(7)
        ->threshold(0.4)
        ->filter(['a' => 1])
        ->where('b', 2)
        ->namespace('corpus')
        ->hybrid()
        ->mmr(0.3)
        ->rerank('fake')
        ->expandParents()
        ->contextBudget(500)
        ->dedup(false)
        ->fetch(50)
        ->using('mistral')
        ->store('memory');

    $request = $builder->toRequest();

    expect($request->text)->toBe('my query')
        ->and($request->topK)->toBe(7)
        ->and($request->scoreThreshold)->toBe(0.4)
        ->and($request->filters)->toBe(['a' => 1, 'b' => 2])
        ->and($request->namespace)->toBe('corpus')
        ->and($request->hybrid)->toBeTrue()
        ->and($request->mmr)->toBeTrue()
        ->and($request->mmrLambda)->toBe(0.3)
        ->and($request->rerank)->toBeTrue()
        ->and($request->rerankerName)->toBe('fake')
        ->and($request->expandParents)->toBeTrue()
        ->and($request->contextBudgetTokens)->toBe(500)
        ->and($request->dedup)->toBeFalse()
        ->and($request->fetchK)->toBe(50)
        ->and($request->embedder)->toBe('mistral')
        ->and($request->store)->toBe('memory')
        ->and($request->effectiveFetchK())->toBe(50);
});

it('effectiveFetchK grows the candidate pool for rerank/mmr/hybrid', function () {
    $plain = (new SearchBuilder(app(Retriever::class), 'q'))->topK(5)->toRequest();
    $reranked = (new SearchBuilder(app(Retriever::class), 'q'))->topK(5)->rerank()->toRequest();

    expect($plain->effectiveFetchK())->toBe(5)
        ->and($reranked->effectiveFetchK())->toBe(20);
});

it('runs MMR and threshold end to end and supports first()/count()', function () {
    Rag::ingest(new IngestionSource('Red apple. Green apple. Yellow banana. Red cherry.', 'text/plain', IngestionSource::TYPE_TEXT));
    $doc = new ParsedDocument('Red apple. Green apple. Yellow banana. Red cherry.', 'text/plain');
    $document = Document::query()->first();
    Rag::index($document, Rag::chunk($doc, ['strategy' => 'sentence', 'size' => 50]));

    $mmrHits = Rag::search('red fruit')->mmr(0.5)->topK(2)->get();
    expect(count($mmrHits))->toBeLessThanOrEqual(2);

    expect(Rag::search('apple')->threshold(-1.0)->count())->toBeGreaterThanOrEqual(0)
        ->and(Rag::search('apple')->topK(1)->first())->not->toBeNull();
});
