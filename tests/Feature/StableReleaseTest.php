<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;
use Sellinnate\RagEngine\Pipeline\SyncModelEmbeddingJob;
use Sellinnate\RagEngine\RagEngineServiceProvider;

it('dispatches indexing jobs onto the configured queue (RAG_QUEUE)', function () {
    config()->set('rag-engine.ingestion.queue', 'rag-indexing');

    $process = new ProcessDocumentJob('doc-1', 'tenant-1');
    $sync = new SyncModelEmbeddingJob('App\\Models\\Post', '1', 'tenant-1');

    expect($process->queue)->toBe('rag-indexing')
        ->and($sync->queue)->toBe('rag-indexing');
});

it('fails closed on an unimplemented tenancy isolation mode', function () {
    config()->set('rag-engine.tenancy.isolation', 'schema');

    (new RagEngineServiceProvider($this->app))->packageBooted();
})->throws(RagException::class, 'not implemented');

it('accepts the supported namespace isolation mode', function () {
    config()->set('rag-engine.tenancy.isolation', 'namespace');

    (new RagEngineServiceProvider($this->app))->packageBooted();

    expect(true)->toBeTrue(); // no exception thrown
});
