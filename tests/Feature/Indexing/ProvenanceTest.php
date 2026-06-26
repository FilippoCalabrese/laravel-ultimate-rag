<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;

it('makes every chunk traceable to its source URL', function () {
    $url = 'https://example.com/articles/solar.html';
    $document = Rag::ingest(new IngestionSource(
        'Photovoltaic panels convert sunlight into electricity.',
        'text/plain',
        IngestionSource::TYPE_URL,
        ['url' => $url],
    ));
    Rag::process($document);

    $hits = Rag::search('photovoltaic sunlight')->topK(5)->get();

    expect($hits)->not->toBeEmpty();
    $hit = $hits[0];
    expect($hit->metadata['source_type'])->toBe('url')
        ->and($hit->metadata['source_ref'])->toBe($url)
        ->and($hit->documentId)->toBe((string) $document->id);
});

it('uses the filename as the source reference for uploads', function () {
    $document = Rag::ingest(new IngestionSource(
        'Quarterly revenue figures and analysis.',
        'text/plain',
        IngestionSource::TYPE_UPLOAD,
        ['filename' => 'q3-report.txt'],
    ));
    Rag::process($document);

    $hit = Rag::search('quarterly revenue')->topK(3)->get()[0];

    expect($hit->metadata['source_type'])->toBe('upload')
        ->and($hit->metadata['source_ref'])->toBe('q3-report.txt');
});

it('always carries the document id even without a human reference', function () {
    $document = Rag::ingest(new IngestionSource(
        'Plain text with no external reference.',
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));
    Rag::process($document);

    $hit = Rag::search('plain text reference')->topK(3)->get()[0];

    expect($hit->metadata['source_type'])->toBe('text')
        ->and($hit->documentId)->toBe((string) $document->id);
});

it('resolve() returns null for a non-model source', function () {
    $document = Rag::ingest(new IngestionSource(
        'A document that is not backed by an Eloquent model.',
        'text/plain',
        IngestionSource::TYPE_URL,
        ['url' => 'https://example.com/x'],
    ));
    Rag::process($document);

    $hit = Rag::search('document eloquent model')->topK(3)->get()[0];

    expect(app(ModelEmbedder::class)->resolve($hit))->toBeNull();
});
