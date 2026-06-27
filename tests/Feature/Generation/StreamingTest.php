<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;

beforeEach(function () {
    $document = Rag::ingest(new IngestionSource(
        'Refunds are issued within 14 business days of an approved request.',
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));
    Rag::process($document);
});

it('Rag::ask()->stream() yields answer tokens (fake LLM)', function () {
    config()->set('rag-engine.defaults.llm', 'fake');

    $out = '';
    foreach (Rag::ask('how long do refunds take?')->stream() as $token) {
        $out .= $token;
    }

    expect($out)->toContain('ANSWER:');
});

it('Rag::ask()->using(anthropic)->stream() streams SSE deltas end to end', function () {
    $sse = implode("\n", [
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Refunds "}}',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"take 14 days."}}',
        'data: {"type":"message_stop"}',
    ]);
    Http::fake(['*/v1/messages' => Http::response($sse)]);

    $out = '';
    foreach (Rag::ask('how long do refunds take?')->using('anthropic')->stream() as $token) {
        $out .= $token;
    }

    expect($out)->toBe('Refunds take 14 days.');
});

it('streaming with the null LLM yields nothing (isolated)', function () {
    config()->set('rag-engine.defaults.llm', 'null');

    $tokens = iterator_to_array(Rag::ask('anything')->stream(), false);

    expect($tokens)->toBe([]);
});
