<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Retrieval\Retriever;
use Sellinnate\RagEngine\Retrieval\SearchBuilder;

beforeEach(function () {
    foreach (['Solar panels convert sunlight into electricity.', 'Refunds take 14 days.'] as $text) {
        $document = Rag::ingest(new IngestionSource($text, 'text/plain', IngestionSource::TYPE_TEXT));
        Rag::process($document);
    }
});

it('the builder records expandQueries in the request', function () {
    $request = (new SearchBuilder(app(Retriever::class), 'q'))->expandQueries(4)->toRequest();

    expect($request->expandQueries)->toBeTrue()
        ->and($request->queryVariations)->toBe(4);
});

it('expandQueries retrieves and fuses variants with a fake LLM', function () {
    config()->set('rag-engine.defaults.llm', 'fake');

    $hits = Rag::search('how do solar panels work?')->expandQueries(3)->topK(5)->get();

    expect($hits)->not->toBeEmpty();
});

it('expandQueries degrades gracefully with the null LLM (original query only)', function () {
    config()->set('rag-engine.defaults.llm', 'null');

    $hits = Rag::search('solar electricity')->expandQueries(3)->topK(5)->get();

    expect($hits)->not->toBeEmpty();
});
