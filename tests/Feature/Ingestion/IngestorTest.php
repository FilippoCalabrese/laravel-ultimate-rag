<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Events\DocumentIngested;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;

beforeEach(fn () => $this->ingestor = app(Ingestor::class));

function source(string $content = 'hello world', array $metadata = []): IngestionSource
{
    return new IngestionSource($content, 'text/plain', IngestionSource::TYPE_TEXT, $metadata);
}

it('ingests a source into an encrypted document with provenance (FR-IN-07)', function () {
    Event::fake();

    $document = $this->ingestor->ingest(source('secret body'), ['tag' => 'demo']);

    expect($document->status)->toBe('pending')
        ->and($document->version)->toBe(1)
        ->and($document->content_hash)->toBe(hash('sha256', 'secret body'))
        ->and($document->dek_id)->toBe('default')
        ->and($document->encrypted_content_ref)->not->toContain('secret body')
        ->and($document->metadata['tag'])->toBe('demo')
        ->and($document->metadata['provenance']['source_type'])->toBe('text')
        ->and($document->metadata['provenance']['size'])->toBe(11);

    Event::assertDispatched(DocumentIngested::class);
});

it('round-trips the decrypted content (FR-SEC-01)', function () {
    $document = $this->ingestor->ingest(source('round trip me'));

    expect($this->ingestor->content($document))->toBe('round trip me');
});

it('deduplicates identical content idempotently (FR-IN-06)', function () {
    $first = $this->ingestor->ingest(source('same content'));
    $second = $this->ingestor->ingest(source('same content'));

    expect($second->id)->toBe($first->id)
        ->and(Document::count())->toBe(1);
});

it('versions a document by document_key and supersedes the prior (FR-IN-08)', function () {
    $v1 = $this->ingestor->ingest(source('version one', ['document_key' => 'doc-1']));
    $v2 = $this->ingestor->ingest(source('version two', ['document_key' => 'doc-1']));

    expect($v1->fresh()->status)->toBe('superseded')
        ->and($v1->fresh()->soft_deleted_at)->not->toBeNull()
        ->and($v2->version)->toBe(2)
        ->and($v2->status)->toBe('pending');
});

it('stores plaintext (base64) when encryption is disabled', function () {
    config()->set('rag-engine.security.encryption_enabled', false);

    $document = $this->ingestor->ingest(source('plain content'));

    expect($document->dek_id)->toBeNull()
        ->and($this->ingestor->content($document))->toBe('plain content');
});

it('enforces the maximum source size (FR-IN-01)', function () {
    config()->set('rag-engine.ingestion.max_upload_bytes', 5);

    $this->ingestor->ingest(source('this is definitely longer than five bytes'));
})->throws(RagException::class, 'maximum size');

it('soft-deletes a document (FR-IN-10)', function () {
    $document = $this->ingestor->ingest(source('to delete'));

    $this->ingestor->softDelete($document);

    expect($document->fresh()->status)->toBe('deleted')
        ->and($document->fresh()->soft_deleted_at)->not->toBeNull();
});

it('purges a document and its chunks, crypto-shredding it (FR-IN-10, FR-SEC-04)', function () {
    Event::fake();
    $document = $this->ingestor->ingest(source('to purge'));
    Chunk::create([
        'document_id' => $document->id,
        'tenant_id' => $document->tenant_id,
        'content' => 'chunk',
        'position' => 0,
    ]);

    $this->ingestor->purge($document);

    expect(Document::find($document->id))->toBeNull()
        ->and(Chunk::where('document_id', $document->id)->count())->toBe(0);

    Event::assertDispatched(DataShredded::class);
});

it('a soft-deleted document does not block re-ingestion of the same content', function () {
    $document = $this->ingestor->ingest(source('reusable'));
    $this->ingestor->softDelete($document);

    $reingested = $this->ingestor->ingest(source('reusable'));

    expect($reingested->id)->not->toBe($document->id);
});
