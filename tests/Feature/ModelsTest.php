<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Models\AuditEntry;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;

it('persists a document with a uuid and casts metadata', function () {
    $document = Document::factory()->create(['metadata' => ['title' => 'Hello']]);

    expect($document->id)->toBeString()->toHaveLength(36)
        ->and($document->fresh()->metadata)->toBe(['title' => 'Hello'])
        ->and($document->getTable())->toBe('rag_documents');
});

it('relates chunks to a document', function () {
    $document = Document::factory()->create();

    $chunk = Chunk::create([
        'document_id' => $document->id,
        'tenant_id' => $document->tenant_id,
        'content' => 'chunk body',
        'position' => 0,
    ]);

    expect($document->chunks)->toHaveCount(1)
        ->and($chunk->document->id)->toBe($document->id);
});

it('appends an audit entry', function () {
    $entry = AuditEntry::create([
        'tenant_id' => 't1',
        'action' => 'document.ingested',
        'target' => 'doc-1',
        'hash' => str_repeat('a', 64),
    ]);

    expect($entry->exists)->toBeTrue();
});

it('blocks updates to audit entries (immutability, NFR-CO-03)', function () {
    $entry = AuditEntry::create(['tenant_id' => 't1', 'action' => 'x', 'hash' => str_repeat('a', 64)]);

    $entry->action = 'tampered';
    $entry->save();
})->throws(RuntimeException::class, 'immutable');

it('blocks deletes of audit entries', function () {
    $entry = AuditEntry::create(['tenant_id' => 't1', 'action' => 'x', 'hash' => str_repeat('a', 64)]);

    $entry->delete();
})->throws(RuntimeException::class, 'immutable');

it('enforces immutability at the DB layer against mass update (NFR-CO-03)', function () {
    AuditEntry::create(['tenant_id' => 't1', 'action' => 'x', 'hash' => str_repeat('a', 64)]);

    AuditEntry::query()->where('action', 'x')->update(['action' => 'tampered']);
})->throws(QueryException::class, 'immutable');

it('enforces immutability against quiet saves that bypass model events', function () {
    $entry = AuditEntry::create(['tenant_id' => 't1', 'action' => 'x', 'hash' => str_repeat('a', 64)]);

    $entry->action = 'tampered';
    $entry->saveQuietly();
})->throws(QueryException::class, 'immutable');

it('enforces immutability against raw DB::table update and delete', function () {
    $table = config('rag-engine.tables.audit_entries');
    AuditEntry::create(['tenant_id' => 't1', 'action' => 'x', 'hash' => str_repeat('a', 64)]);

    expect(fn () => DB::table($table)->update(['action' => 'tampered']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table($table)->delete())
        ->toThrow(QueryException::class);
});
