<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\ShreddedTenant;

it('rag:status reports document counts by status (FR-DX-05)', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('a', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:status', ['--tenant' => 't1'])
        ->assertSuccessful();
});

it('rag:stats shows usage for a tenant', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('content here', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:stats', ['tenant' => 't1'])->assertSuccessful();
});

it('rag:rotate-keys rotates a tenant key (FR-SEC-05)', function () {
    Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('rotate this', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:rotate-keys', ['tenant' => 't1'])
        ->expectsOutputToContain('Rotated key')
        ->assertSuccessful();
});

it('rag:purge crypto-shreds a tenant with --force (FR-SEC-04)', function () {
    Rag::forTenant('doomed', fn () => Rag::ingest(new IngestionSource('erase', 'text/plain', IngestionSource::TYPE_TEXT)));

    $this->artisan('rag:purge', ['tenant' => 'doomed', '--force' => true])->assertSuccessful();

    expect(ShreddedTenant::whereKey('doomed')->exists())->toBeTrue();
});

it('rag:purge aborts without --force when declined', function () {
    $this->artisan('rag:purge', ['tenant' => 't1'])
        ->expectsConfirmation('Permanently crypto-shred tenant [t1]? This is irreversible.', 'no')
        ->assertFailed();
});

it('rag:reconcile reports consistency (NFR-DR-02)', function () {
    $doc = Rag::forTenant('t1', fn () => Rag::ingest(new IngestionSource('reconcile me content', 'text/plain', IngestionSource::TYPE_TEXT)));
    Rag::forTenant('t1', fn () => Rag::process($doc));

    $this->artisan('rag:reconcile', ['tenant' => 't1'])->assertSuccessful();
});

it('rag:clear-cache clears the cache', function () {
    $this->artisan('rag:clear-cache')->assertSuccessful();
});
