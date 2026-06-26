<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Sellinnate\RagEngine\Events\ChunksEmbedded;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Events\DocumentChunked;
use Sellinnate\RagEngine\Events\DocumentIndexed;
use Sellinnate\RagEngine\Events\DocumentIngested;
use Sellinnate\RagEngine\Events\IngestionFailed;
use Sellinnate\RagEngine\Events\KeyRotated;
use Sellinnate\RagEngine\Events\SearchPerformed;

it('carries lifecycle payloads (FR-EV-01)', function () {
    expect((new DocumentIngested('doc', 't1'))->documentId)->toBe('doc')
        ->and((new DocumentChunked('doc', 't1', 5))->chunkCount)->toBe(5)
        ->and((new ChunksEmbedded('doc', 't1', 5))->chunkCount)->toBe(5)
        ->and((new DocumentIndexed('doc', 't1', 'ns'))->namespace)->toBe('ns')
        ->and((new SearchPerformed('t1', 'query', 3))->resultCount)->toBe(3)
        ->and((new IngestionFailed('doc', 't1', 'boom'))->reason)->toBe('boom')
        ->and((new KeyRotated('kek', 't1'))->keyId)->toBe('kek')
        ->and((new DataShredded('kek', 't1', 'document'))->scope)->toBe('document');
});

it('events are dispatchable through the framework', function () {
    Event::fake();

    DocumentIngested::dispatch('doc', 't1');

    Event::assertDispatched(DocumentIngested::class);
});
