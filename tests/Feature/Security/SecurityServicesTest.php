<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Events\KeyRotated;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\ShreddedTenant;
use Sellinnate\RagEngine\Security\CryptoShredder;
use Sellinnate\RagEngine\Security\KeyRotationService;

it('crypto-shreds a tenant, registers it, and emits the event (FR-SEC-04)', function () {
    Event::fake([DataShredded::class]);

    Rag::forTenant('doomed', function () {
        Rag::ingest(new IngestionSource('sensitive personal data', 'text/plain', IngestionSource::TYPE_TEXT));
    });

    app(CryptoShredder::class)->shredTenant('doomed', 'gdpr-request');

    expect(ShreddedTenant::whereKey('doomed')->exists())->toBeTrue()
        ->and(Document::where('tenant_id', 'doomed')->count())->toBe(0)
        ->and(app(CryptoShredder::class)->isShredded('doomed'))->toBeTrue();

    Event::assertDispatched(DataShredded::class);
});

it('deletes plaintext vectors from the store on shred (C2, GDPR erasure)', function () {
    Rag::forTenant('vic', function () {
        $doc = Rag::ingest(new IngestionSource('the secret password is hunter2', 'text/plain', IngestionSource::TYPE_TEXT));
        Rag::process($doc, ['strategy' => 'sentence']);
    });

    $namespace = config('rag-engine.namespace');
    expect(Rag::vectorStore()->count($namespace))->toBeGreaterThan(0);

    app(CryptoShredder::class)->shredTenant('vic');

    // No vector for the shredded tenant remains (plaintext erased).
    $remaining = Rag::vectorStore()->search($namespace, array_fill(0, 8, 0.1),
        new RetrievalQuery('q', topK: 100, tenantId: 'vic'));
    expect($remaining)->toBeEmpty();
});

it('makes a shredded tenant unrecoverable and un-reprovisionable (FR-SEC-04, M1)', function () {
    $document = Rag::forTenant('erase-me', fn () => Rag::ingest(
        new IngestionSource('secret content', 'text/plain', IngestionSource::TYPE_TEXT)
    ));

    app(CryptoShredder::class)->shredTenant('erase-me');

    // Re-provisioning the shredded tenant is refused.
    expect(fn () => Rag::forTenant('erase-me', fn () => Rag::ingest(
        new IngestionSource('new content', 'text/plain', IngestionSource::TYPE_TEXT)
    )))->toThrow(RagException::class, 'crypto-shredded');
});

it('shreds a single document (FR-SEC-04)', function () {
    $document = Rag::forTenant('t1', fn () => Rag::ingest(
        new IngestionSource('doc content', 'text/plain', IngestionSource::TYPE_TEXT)
    ));

    app(CryptoShredder::class)->shredDocument($document);

    expect(Document::find($document->id))->toBeNull();
});

it('rotates a key and re-wraps DEKs while content stays decryptable (FR-SEC-05)', function () {
    Event::fake([KeyRotated::class]);

    $document = Rag::forTenant('rotate-me', fn () => Rag::ingest(
        new IngestionSource('rotatable content here', 'text/plain', IngestionSource::TYPE_TEXT)
    ));
    $wrappedBefore = json_decode($document->fresh()->encrypted_content_ref, true)['wrapped_dek'];

    $rewrapped = app(KeyRotationService::class)->rotate('rotate-me');

    $document = $document->fresh();
    $wrappedAfter = json_decode($document->encrypted_content_ref, true)['wrapped_dek'];

    expect($rewrapped)->toBeGreaterThan(0)
        ->and($wrappedAfter)->not->toBe($wrappedBefore) // re-wrapped under the new KEK
        ->and(app(Ingestor::class)->content($document))->toBe('rotatable content here'); // still decryptable

    Event::assertDispatched(KeyRotated::class);
});
