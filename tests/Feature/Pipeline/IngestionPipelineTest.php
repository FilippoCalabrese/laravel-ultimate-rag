<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Events\DocumentChunked;
use Sellinnate\RagEngine\Events\DocumentIndexed;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Exceptions\QuotaExceededException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;

it('runs the full pipeline and transitions to indexed (FR-OR-02)', function () {
    Event::fake([DocumentChunked::class, DocumentIndexed::class]);

    $document = Rag::ingest(new IngestionSource(
        "First sentence here. Second sentence here. Third one.\n\nNew paragraph content.",
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));

    $count = app(IngestionPipeline::class)->process($document, ['strategy' => 'sentence']);

    expect($count)->toBeGreaterThan(0)
        ->and($document->fresh()->status)->toBe('indexed')
        ->and(Chunk::where('document_id', $document->id)->count())->toBe($count);

    Event::assertDispatched(DocumentChunked::class);
    Event::assertDispatched(DocumentIndexed::class);
});

it('redacts PII end-to-end so indexed chunks never contain it', function () {
    $document = Rag::ingest(new IngestionSource(
        'Contact john.doe@example.com for details about the report.',
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));

    app(IngestionPipeline::class)->process($document);

    // The indexed (searchable) content is PII-redacted.
    $hits = Rag::search('contact details report')->topK(5)->get();
    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit->content)->not->toContain('john.doe@example.com')
            ->and($hit->content)->toContain('[EMAIL]');
    }
});

it('marks the document failed when a stage throws (FR-OR-05)', function () {
    $document = Rag::ingest(new IngestionSource('content', 'application/x-unsupported', IngestionSource::TYPE_TEXT));

    expect(fn () => app(IngestionPipeline::class)->process($document))
        ->toThrow(ParsingException::class);

    expect($document->fresh()->status)->toBe('failed');
});

it('detects language during preprocessing', function () {
    $document = Rag::ingest(new IngestionSource(
        'il cane e la gatta sono nel giardino con una palla per il gioco di oggi',
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));

    app(IngestionPipeline::class)->process($document);

    expect($document->fresh()->language)->toBe('it');
});

it('enforces the document quota during ingestion (FR-MT-04)', function () {
    config()->set('rag-engine.tenancy.quotas.max_documents', 1);

    Rag::forTenant('limited', fn () => Rag::ingest(new IngestionSource('one', 'text/plain', IngestionSource::TYPE_TEXT)));

    expect(fn () => Rag::forTenant('limited', fn () => Rag::ingest(new IngestionSource('two', 'text/plain', IngestionSource::TYPE_TEXT))))
        ->toThrow(QuotaExceededException::class, 'document quota');
});
