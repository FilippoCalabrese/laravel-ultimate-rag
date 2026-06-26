<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Data\VectorRecord;

it('EmbeddingResponse enforces dimensional consistency (FR-EM-10)', function () {
    new EmbeddingResponse([[0.1, 0.2], [0.3]], 'm', 2, Usage::zero());
})->throws(InvalidArgumentException::class, 'dimensions');

it('EmbeddingResponse rejects out-of-range index access', function () {
    $response = new EmbeddingResponse([[0.1, 0.2]], 'm', 2, Usage::zero());

    $response->vectorAt(5);
})->throws(InvalidArgumentException::class);

it('Usage sums tokens and cost', function () {
    $total = (new Usage(10, 1.5))->plus(new Usage(5, 0.5));

    expect($total->tokens)->toBe(15)->and($total->cost)->toBe(2.0);
});

it('VectorRecord rejects empty vectors', function () {
    new VectorRecord('id', []);
})->throws(InvalidArgumentException::class);

it('VectorRecord exposes dimensions and tenant id', function () {
    $record = new VectorRecord('id', [0.1, 0.2, 0.3], ['tenant_id' => 't1']);

    expect($record->dimensions())->toBe(3)->and($record->tenantId())->toBe('t1');
});

it('TextChunk builds embeddable text with a context header (FR-CH-08)', function () {
    $chunk = (new TextChunk('body', 0))->withContextHeader('Section: Intro');

    expect($chunk->embeddableText())->toBe("Section: Intro\n\nbody");
});

it('TextChunk without a header embeds raw content', function () {
    expect((new TextChunk('body', 0))->embeddableText())->toBe('body');
});

it('TextChunk is immutable through with-methods', function () {
    $chunk = new TextChunk('body', 0);
    $updated = $chunk->withMetadata(['page' => 2])->withTokenCount(7);

    expect($chunk->metadata)->toBe([])
        ->and($updated->metadata)->toBe(['page' => 2])
        ->and($updated->tokenCount)->toBe(7);
});

it('ParsedDocument merges metadata and sets language immutably', function () {
    $doc = new ParsedDocument('text', 'text/plain', metadata: ['a' => 1]);
    $updated = $doc->withMetadata(['b' => 2])->withLanguage('it')->withText('new');

    expect($doc->metadata)->toBe(['a' => 1])
        ->and($updated->metadata)->toBe(['a' => 1, 'b' => 2])
        ->and($updated->language)->toBe('it')
        ->and($updated->text)->toBe('new')
        ->and($doc->toArray())->toHaveKeys(['text', 'mime_type', 'sections', 'metadata', 'language']);
});

it('RetrievalQuery merges filters and overrides top-k immutably', function () {
    $query = new RetrievalQuery('q', topK: 5, filters: ['tenant_id' => 't1']);
    $updated = $query->withFilters(['tag' => 'x'])->withTopK(20);

    expect($query->topK)->toBe(5)
        ->and($updated->topK)->toBe(20)
        ->and($updated->filters)->toBe(['tenant_id' => 't1', 'tag' => 'x']);
});
