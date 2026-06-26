<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Models\AuditEntry;
use Sellinnate\RagEngine\Tenancy\TenantContext;

beforeEach(fn () => $this->audit = app(AuditLogger::class));

it('appends a hash-chained entry linking to the previous one (NFR-CO-03)', function () {
    $first = $this->audit->log('document.ingested', 'doc-1');
    $second = $this->audit->log('document.indexed', 'doc-1');

    expect($first->hash_prev)->toBeNull()
        ->and($first->hash)->toHaveLength(64)
        ->and($second->hash_prev)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash);
});

it('verifies an untampered chain', function () {
    $this->audit->log('a');
    $this->audit->log('b');
    $this->audit->log('c');

    expect($this->audit->verify('default'))->toBeTrue();
});

it('detects tampering when an entry is mutated at the DB layer', function () {
    $this->audit->log('a');
    $entry = $this->audit->log('b', 'target');
    $this->audit->log('c');

    // The WORM triggers block normal mutation; disable them to simulate a
    // back-door tamper and prove the hash chain still catches it.
    DB::statement('DROP TRIGGER IF EXISTS rag_audit_entries_no_update');
    DB::table('rag_audit_entries')->where('id', $entry->id)->update(['target' => 'tampered']);

    expect($this->audit->verify('default'))->toBeFalse();
});

it('detects a truncation attack (deletion of the last entry) (C1)', function () {
    $this->audit->log('a');
    $this->audit->log('b');
    $last = $this->audit->log('c');

    // Simulate a backup/DBA attacker truncating the tail of the log.
    DB::statement('DROP TRIGGER IF EXISTS rag_audit_entries_no_delete');
    DB::table('rag_audit_entries')->where('id', $last->id)->delete();

    // The anchor still records seq=3 / head=c.hash → mismatch is detected.
    expect($this->audit->verify('default'))->toBeFalse();
});

it('detects wholesale deletion of a tenant chain (C1)', function () {
    $this->audit->log('a');
    $this->audit->log('b');

    DB::statement('DROP TRIGGER IF EXISTS rag_audit_entries_no_delete');
    DB::table('rag_audit_entries')->where('tenant_id', 'default')->delete();

    expect($this->audit->verify('default'))->toBeFalse();
});

it('scopes the chain per tenant', function () {
    app(TenantContext::class)->runAs('t1', fn () => $this->audit->log('x'));
    app(TenantContext::class)->runAs('t2', fn () => $this->audit->log('y'));

    expect(AuditEntry::where('tenant_id', 't1')->first()->hash_prev)->toBeNull()
        ->and(AuditEntry::where('tenant_id', 't2')->first()->hash_prev)->toBeNull();
});
