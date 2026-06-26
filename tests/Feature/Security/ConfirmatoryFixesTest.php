<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Security\CryptoShredder;

it('crypto-shred erases vectors even from an ABANDONED namespace after a move', function () {
    $document = Rag::forTenant('mover', fn () => Rag::ingest(
        new IngestionSource('movable secret content here', 'text/plain', IngestionSource::TYPE_TEXT)
    ));

    // Index into namespace A, then re-index the SAME document into namespace B.
    Rag::forTenant('mover', function () use ($document) {
        Rag::index($document, Rag::chunk(new ParsedDocument('movable secret content here', 'text/plain'), ['strategy' => 'sentence']), ['namespace' => 'ns-a']);
        Rag::index($document->fresh(), Rag::chunk(new ParsedDocument('movable secret content here', 'text/plain'), ['strategy' => 'sentence']), ['namespace' => 'ns-b']);
    });

    // Moving to ns-b must have swept ns-a (no orphaned plaintext vectors).
    expect(Rag::vectorStore()->count('ns-a'))->toBe(0)
        ->and(Rag::vectorStore()->count('ns-b'))->toBeGreaterThan(0);

    app(CryptoShredder::class)->shredTenant('mover');

    expect(Rag::vectorStore()->count('ns-b'))->toBe(0);
});

it('audit chain stays valid across many sequential appends (anchor)', function () {
    $audit = app(AuditLogger::class);
    for ($i = 0; $i < 25; $i++) {
        $audit->log("action.{$i}", "target-{$i}");
    }

    expect($audit->verify('default'))->toBeTrue();
});

it('the unique (tenant_id, seq) index rejects a forged duplicate sequence', function () {
    app(AuditLogger::class)->log('a');

    // A second entry forging seq=1 for the same tenant is rejected by the DB.
    DB::table('rag_audit_entries')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => 'default',
        'action' => 'forged',
        'seq' => 1,
        'hash' => str_repeat('a', 64),
        'created_at' => now(),
    ]);
})->throws(QueryException::class);
