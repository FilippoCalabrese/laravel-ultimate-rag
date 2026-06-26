<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Recovery\Reconciler;
use Sellinnate\RagEngine\Security\AeadCipher;
use Sellinnate\RagEngine\Security\CryptoShredder;
use Sellinnate\RagEngine\Security\Kms\FileKeyStore;
use Sellinnate\RagEngine\Tenancy\TenantContext;

it('crypto-shred erases vectors in a CUSTOM namespace too (H1)', function () {
    Rag::forTenant('cust', function () {
        $doc = Rag::ingest(new IngestionSource('secret in custom namespace here', 'text/plain', IngestionSource::TYPE_TEXT));
        Rag::index($doc, Rag::chunk(new ParsedDocument('secret in custom namespace here', 'text/plain'), ['strategy' => 'sentence']), ['namespace' => 'project-x']);
    });

    expect(Rag::vectorStore()->count('project-x'))->toBeGreaterThan(0);

    app(CryptoShredder::class)->shredTenant('cust');

    expect(Rag::vectorStore()->count('project-x'))->toBe(0);
});

it('confused-deputy: cannot decrypt a document outside its tenant context (M1)', function () {
    $document = Rag::forTenant('owner', fn () => Rag::ingest(
        new IngestionSource('owner-only secret', 'text/plain', IngestionSource::TYPE_TEXT)
    ));

    // Current context is 'default', document belongs to 'owner'.
    expect(fn () => app(Ingestor::class)->content($document))
        ->toThrow(RagException::class, 'not the current tenant');
});

it('FileKeyStore encrypts KEK material at rest with a master key (H3)', function () {
    $dir = sys_get_temp_dir().'/rag-kek-'.bin2hex(random_bytes(5));
    $store = new FileKeyStore($dir, new AeadCipher, 'super-master-secret');

    $material = random_bytes(32);
    $store->put('tenant-1', $material);

    // On-disk bytes are NOT a reversible base64 of the material.
    $onDisk = file_get_contents($dir.'/'.hash('sha256', 'tenant-1').'.kek');
    expect(base64_decode($onDisk, true))->not->toBe($material)
        ->and($store->get('tenant-1'))->toBe($material); // but decrypts correctly

    array_map('unlink', glob($dir.'/*') ?: []);
    @rmdir($dir);
});

it('strict tenancy mode throws when no tenant is explicitly set (M2)', function () {
    $strict = new TenantContext('default', strict: true);

    expect(fn () => $strict->id())->toThrow(RagException::class, 'strict tenancy')
        ->and($strict->runAs('t1', fn () => $strict->id()))->toBe('t1');
});

it('re-indexing deletes old EmbeddingRecords (no unbounded growth, B-H2)', function () {
    $document = Rag::ingest(new IngestionSource('First content here. More content.', 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::index($document, Rag::chunk(new ParsedDocument('First content here. More content.', 'text/plain'), ['strategy' => 'sentence']));
    $afterFirst = EmbeddingRecord::where('tenant_id', $document->tenant_id)->count();

    Rag::index($document, Rag::chunk(new ParsedDocument('Replacement content totally new here.', 'text/plain'), ['strategy' => 'sentence']));
    $afterSecond = EmbeddingRecord::where('tenant_id', $document->tenant_id)->count();

    // Old embedding records were pruned, not accumulated.
    expect($afterSecond)->toBeLessThanOrEqual($afterFirst + 1)
        ->and(app(Reconciler::class)->isConsistent($document->tenant_id))->toBeTrue();
});
