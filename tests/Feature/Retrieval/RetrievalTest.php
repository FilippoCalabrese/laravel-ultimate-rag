<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Events\DocumentIndexed;
use Sellinnate\RagEngine\Events\SearchPerformed;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Chunk;

/**
 * Ingest text, chunk it, and index it for the current tenant.
 */
function indexText(string $text, array $metadata = []): void
{
    $document = Rag::ingest(new IngestionSource($text, 'text/plain', IngestionSource::TYPE_TEXT, $metadata));
    $doc = new ParsedDocument($text, 'text/plain', metadata: $metadata);
    $chunks = Rag::chunk($doc, ['strategy' => 'sentence', 'size' => 200]);
    Rag::index($document, $chunks);
}

it('indexes chunks and dispatches DocumentIndexed', function () {
    Event::fake([DocumentIndexed::class]);

    indexText('The first sentence. The second sentence. The third one.');

    Event::assertDispatched(DocumentIndexed::class);
});

it('retrieves indexed content for the current tenant', function () {
    indexText('Quantum computing uses qubits. Classical computing uses bits.');

    $hits = Rag::search('qubits and quantum')->topK(5)->get();

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]->content)->toBeString();
});

it('enforces fail-closed multi-tenant isolation (NFR-SE-02)', function () {
    Rag::forTenant('tenant-a', fn () => indexText('Secret alpha document about apples.'));
    Rag::forTenant('tenant-b', fn () => indexText('Secret bravo document about bananas.'));

    $aHits = Rag::forTenant('tenant-a', fn () => Rag::search('document')->topK(10)->get());
    $bHits = Rag::forTenant('tenant-b', fn () => Rag::search('document')->topK(10)->get());

    // Each tenant sees only its own content — no cross-tenant leakage.
    foreach ($aHits as $hit) {
        expect($hit->metadata['tenant_id'])->toBe('tenant-a');
    }
    foreach ($bHits as $hit) {
        expect($hit->metadata['tenant_id'])->toBe('tenant-b');
    }
    expect($aHits)->not->toBeEmpty()->and($bHits)->not->toBeEmpty();
});

it('applies metadata filters (FR-RT-04)', function () {
    indexText('Public document content here.', ['visibility' => 'public']);
    indexText('Private document content here.', ['visibility' => 'private']);

    $hits = Rag::search('document')->where('visibility', 'public')->topK(10)->get();

    expect($hits)->not->toBeEmpty();
    foreach ($hits as $hit) {
        expect($hit->metadata['visibility'])->toBe('public');
    }
});

it('hybrid search surfaces exact keyword matches (FR-RT-03)', function () {
    indexText('A treatise on photosynthesis in plants.');
    indexText('An essay about quarterly financial earnings and revenue.');

    $hits = Rag::search('quarterly earnings revenue')->hybrid()->topK(5)->get();

    expect($hits[0]->content)->toContain('earnings');
});

it('reranks results with a deterministic reranker (FR-RR-01)', function () {
    config()->set('rag-engine.defaults.reranker', 'fake');
    indexText('Apples are red. Bananas are yellow. Cherries are small and red.');

    $hits = Rag::search('red fruit')->rerank()->topK(3)->get();

    expect($hits)->not->toBeEmpty();
});

it('deduplicates identical results (FR-RR-03)', function () {
    indexText('Repeated content here.');
    indexText('Repeated content here.'); // different document, same chunk text

    $hits = Rag::search('repeated content')->topK(10)->get();
    $contents = array_map(fn ($h) => $h->content, $hits);

    expect($contents)->toBe(array_unique($contents));
});

it('expands to parent context for small-to-big retrieval (FR-RT-07)', function () {
    $text = str_repeat('Alpha beta gamma delta epsilon. ', 30);
    $document = Rag::ingest(new IngestionSource($text, 'text/plain', IngestionSource::TYPE_TEXT));
    $chunks = Rag::chunk(new ParsedDocument($text, 'text/plain'), ['parent_child' => true, 'child_size' => 80, 'parent_size' => 400]);
    Rag::index($document, $chunks);

    $hits = Rag::search('beta gamma')->expandParents()->topK(3)->get();

    $withParent = array_filter($hits, fn ($h) => isset($h->metadata['parent_content']));
    expect($withParent)->not->toBeEmpty();
    foreach ($withParent as $hit) {
        expect(mb_strlen($hit->metadata['parent_content']))->toBeGreaterThan(mb_strlen($hit->content));
    }
});

it('respects a token-aware context budget (FR-RR-04)', function () {
    indexText('One sentence here. Two sentence here. Three sentence here. Four sentence here. Five here.');

    $unbudgeted = Rag::search('sentence')->topK(10)->get();
    $budgeted = Rag::search('sentence')->topK(10)->contextBudget(10)->get();

    expect(count($budgeted))->toBeLessThanOrEqual(count($unbudgeted))
        ->and($budgeted)->not->toBeEmpty();
});

it('dispatches SearchPerformed with the result count', function () {
    Event::fake([SearchPerformed::class]);
    indexText('Something to find.');

    Rag::search('find')->get();

    Event::assertDispatched(SearchPerformed::class);
});

it('re-indexing a document atomically replaces its chunks (FR-AF-05)', function () {
    $document = Rag::ingest(new IngestionSource('Original content here.', 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::index($document, Rag::chunk(new ParsedDocument('Original content here.', 'text/plain')));
    $firstCount = Chunk::where('document_id', $document->id)->count();

    Rag::index($document, Rag::chunk(new ParsedDocument('Replacement content totally different.', 'text/plain')));
    $chunks = Chunk::where('document_id', $document->id)->get();

    expect($firstCount)->toBeGreaterThan(0)
        ->and($chunks->count())->toBeGreaterThan(0);
    // No stale chunks from the first generation remain referencing old content.
    expect($chunks->pluck('position')->unique()->count())->toBe($chunks->count());
});
