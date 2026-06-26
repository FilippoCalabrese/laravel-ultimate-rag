<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;
use Sellinnate\RagEngine\VectorStore\QdrantStore;

function indexDoc(string $text): Document
{
    $document = Rag::ingest(new IngestionSource($text, 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::index($document, Rag::chunk(new ParsedDocument($text, 'text/plain'), ['strategy' => 'sentence']));

    return $document;
}

it('a tenant filter cannot widen scope beyond the context (H1)', function () {
    Rag::forTenant('tenant-a', fn () => indexDoc('Alpha content for tenant A.'));
    Rag::forTenant('tenant-b', fn () => indexDoc('Bravo content for tenant B.'));

    // Malicious attempt: query as tenant-a but pass a tenant_id filter for B.
    $hits = Rag::forTenant('tenant-a', fn () => Rag::search('content')->where('tenant_id', 'tenant-b')->topK(10)->get());

    // The context tenant (A) overrides the filter — only A's data, never B's.
    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit->metadata['tenant_id'])->toBe('tenant-a')
            ->and($hit->content)->not->toContain('Bravo');
    }
});

it('rejects a dimension change on a populated namespace (H2)', function () {
    $store = new InMemoryVectorStore;
    $store->createNamespace('docs', 8, 'cosine');
    $store->upsert('docs', [new VectorRecord('a', array_fill(0, 8, 0.1))]);

    $store->createNamespace('docs', 16, 'cosine');
})->throws(RagException::class, 'cannot change');

it('rejects a query vector of the wrong dimensionality (H2)', function () {
    $store = new InMemoryVectorStore;
    $store->createNamespace('docs', 8, 'cosine');

    $store->search('docs', [0.1, 0.2], new RetrievalQuery('q'));
})->throws(RagException::class, 'dimensions');

it('Qdrant rejects an injection-laden namespace (H3)', function () {
    $store = new QdrantStore(app(Factory::class), 'http://localhost:6333');

    $store->namespaceExists('../../collections/victim');
})->throws(RagException::class, 'Invalid namespace');

it('keeps the prior index intact when re-embedding fails during re-index (C1)', function () {
    // First index with the fake embedder (8 dims) succeeds.
    $document = indexDoc('Original durable content here.');
    $originalChunks = Chunk::where('document_id', $document->id)->count();
    expect($originalChunks)->toBeGreaterThan(0);

    // Re-index with a provider that fails — same dims so the namespace guard passes.
    config()->set('rag-engine.embedders.mistral.dimensions', 8);
    config()->set('rag-engine.embedders.mistral.retries', false);
    config()->set('rag-engine.defaults.embedder', 'mistral');
    Http::fake(['*/embeddings' => Http::response('upstream down', 500)]);

    try {
        Rag::index($document, Rag::chunk(new ParsedDocument('Replacement content here.', 'text/plain'), ['strategy' => 'sentence']));
    } catch (Throwable) {
        // expected: embedding failed
    }

    // The prior generation's chunks were NOT destroyed.
    $afterChunks = Chunk::where('document_id', $document->id)->get();
    expect($afterChunks)->toHaveCount($originalChunks)
        ->and($afterChunks->first()->encrypted_content)->not->toBeNull();
});

it('dedup runs before top-k so unique results backfill (M2)', function () {
    // Index several documents, two with identical chunk text.
    indexDoc('Unique result one about cats.');
    indexDoc('Duplicated shared sentence.');
    indexDoc('Duplicated shared sentence.');
    indexDoc('Unique result two about dogs.');

    $hits = Rag::search('result sentence')->topK(3)->get();
    $contents = array_map(fn ($h) => $h->content, $hits);

    // No duplicate contents, and we still backfill toward topK from the pool.
    expect($contents)->toBe(array_values(array_unique($contents)))
        ->and(count($hits))->toBeGreaterThanOrEqual(2);
});
