<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Generation\GenerationResult;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Recovery\Reconciler;

beforeEach(function () {
    config()->set('rag-engine.defaults.llm', 'fake');
    $doc = Rag::ingest(new IngestionSource('Photosynthesis converts light into chemical energy in plants.', 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::process($doc, ['strategy' => 'sentence']);
});

it('answers a question with cited sources (FR-GE-01/03)', function () {
    $result = Rag::ask('What does photosynthesis do?')->topK(3)->generate();

    expect($result)->toBeInstanceOf(GenerationResult::class)
        ->and($result->answer)->toStartWith('ANSWER:')
        ->and($result->citations)->not->toBeEmpty()
        ->and($result->citations[0])->toHaveKeys(['index', 'document_id', 'chunk_id'])
        ->and($result->sources)->not->toBeEmpty();
});

it('supports a custom prompt template and llm', function () {
    $result = Rag::ask('photosynthesis')
        ->using('fake')
        ->prompt('Q: {question}\nC: {context}')
        ->topK(2)
        ->generate();

    expect($result->answer)->toContain('ANSWER:');
});

it('the generation layer is isolated: the null llm yields an empty answer (FR-GE-05)', function () {
    config()->set('rag-engine.defaults.llm', 'null');

    $result = Rag::ask('anything')->generate();

    expect($result->answer)->toBe('');
});

it('serializes a result to array', function () {
    $result = Rag::ask('photosynthesis')->generate();

    expect($result->toArray())->toHaveKeys(['answer', 'citations', 'sources']);
});

it('reconciliation reports a consistent corpus after indexing (NFR-DR-02)', function () {
    $tenantId = Document::query()->first()->tenant_id;

    expect(app(Reconciler::class)->isConsistent($tenantId))->toBeTrue();
});

it('reconciliation detects an orphan embedding record', function () {
    $tenantId = Document::query()->first()->tenant_id;

    EmbeddingRecord::create([
        'chunk_id' => 'ghost-chunk',
        'tenant_id' => $tenantId,
        'model' => 'm',
        'dimensions' => 8,
        'provider' => 'fake',
    ]);

    $report = app(Reconciler::class)->reconcile($tenantId);

    expect($report['orphan_embeddings'])->toContain('ghost-chunk')
        ->and(app(Reconciler::class)->isConsistent($tenantId))->toBeFalse();
});
