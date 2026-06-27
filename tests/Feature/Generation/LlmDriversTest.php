<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Generation\AnthropicLlm;
use Sellinnate\RagEngine\Generation\OpenAiLlm;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Managers\LlmManager;

function llmHttp(): HttpFactory
{
    return app(HttpFactory::class);
}

it('AnthropicLlm posts to the Messages API and extracts the answer', function () {
    Http::fake(['*/v1/messages' => Http::response([
        'content' => [
            ['type' => 'text', 'text' => 'Refunds take 14 days. [1]'],
        ],
        'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
    ])]);

    $llm = new AnthropicLlm(llmHttp(), 'claude-sonnet-4-6', 'sk-ant-key', 'https://api.anthropic.com', [
        'max_tokens' => 512,
        'system' => 'Be concise.',
    ]);

    $answer = $llm->generate('Question: how long do refunds take?');

    expect($answer)->toBe('Refunds take 14 days. [1]')
        ->and($llm->model())->toBe('claude-sonnet-4-6');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/messages')
        && $r->hasHeader('x-api-key', 'sk-ant-key')
        && $r->hasHeader('anthropic-version', '2023-06-01')
        && $r['model'] === 'claude-sonnet-4-6'
        && $r['max_tokens'] === 512
        && $r['system'] === 'Be concise.'
        && $r['messages'][0]['role'] === 'user');
});

it('AnthropicLlm concatenates multiple text blocks', function () {
    Http::fake(['*/v1/messages' => Http::response([
        'content' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world'],
        ],
    ])]);

    $answer = (new AnthropicLlm(llmHttp(), 'claude-sonnet-4-6', 'k', 'https://api.anthropic.com'))->generate('hi');

    expect($answer)->toBe('Hello world');
});

it('AnthropicLlm raises a RagException on a provider error', function () {
    Http::fake(['*/v1/messages' => Http::response('unauthorized', 401)]);

    (new AnthropicLlm(llmHttp(), 'claude-sonnet-4-6', 'bad', 'https://api.anthropic.com'))->generate('hi');
})->throws(RagException::class, 'anthropic');

it('AnthropicLlm streams text deltas', function () {
    $sse = implode("\n", [
        'event: content_block_delta',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hello "}}',
        'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"world"}}',
        'data: {"type":"message_stop"}',
    ]);
    Http::fake(['*/v1/messages' => Http::response($sse)]);

    $out = '';
    foreach ((new AnthropicLlm(llmHttp(), 'claude-sonnet-4-6', 'k', 'https://api.anthropic.com'))->stream('hi') as $chunk) {
        $out .= $chunk;
    }

    expect($out)->toBe('Hello world');
});

it('OpenAiLlm posts chat completions with Bearer auth and extracts the answer', function () {
    Http::fake(['*/chat/completions' => Http::response([
        'choices' => [['message' => ['role' => 'assistant', 'content' => '42']]],
    ])]);

    $llm = new OpenAiLlm(llmHttp(), 'gpt-4o-mini', 'sk-openai', 'https://api.openai.com/v1', ['max_tokens' => 256]);

    expect($llm->generate('what is 6x7?'))->toBe('42');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/completions')
        && $r->hasHeader('Authorization', 'Bearer sk-openai')
        && $r['model'] === 'gpt-4o-mini'
        && $r['messages'][0]['role'] === 'user'
        && $r['max_tokens'] === 256);
});

it('OpenAiLlm streams content deltas and stops at [DONE]', function () {
    $sse = implode("\n", [
        'data: {"choices":[{"delta":{"content":"4"}}]}',
        'data: {"choices":[{"delta":{"content":"2"}}]}',
        'data: [DONE]',
    ]);
    Http::fake(['*/chat/completions' => Http::response($sse)]);

    $out = '';
    foreach ((new OpenAiLlm(llmHttp(), 'gpt-4o-mini', 'k', 'https://api.openai.com/v1'))->stream('hi') as $chunk) {
        $out .= $chunk;
    }

    expect($out)->toBe('42');
});

it('LlmManager resolves the anthropic and openai drivers from config', function () {
    config()->set('rag-engine.llms.anthropic', ['driver' => 'anthropic', 'api_key' => 'k', 'model' => 'claude-sonnet-4-6']);
    config()->set('rag-engine.llms.openai', ['driver' => 'openai', 'api_key' => 'k', 'model' => 'gpt-4o-mini']);

    $manager = app(LlmManager::class)->forgetDrivers();

    expect($manager->driver('anthropic'))->toBeInstanceOf(AnthropicLlm::class)
        ->and($manager->driver('openai'))->toBeInstanceOf(OpenAiLlm::class);
});

it('Rag::ask()->using(anthropic) generates a cited answer end to end', function () {
    Http::fake(['*/v1/messages' => Http::response([
        'content' => [['type' => 'text', 'text' => 'Refunds take 14 business days. [1]']],
    ])]);

    $document = Rag::ingest(new IngestionSource(
        'Refunds are issued within 14 business days of an approved request.',
        'text/plain',
        IngestionSource::TYPE_TEXT,
    ));
    Rag::process($document);

    $result = Rag::ask('how long do refunds take?')->using('anthropic')->topK(3)->generate();

    expect($result->answer)->toBe('Refunds take 14 business days. [1]')
        ->and($result->citations)->not->toBeEmpty()
        ->and($result->sources)->not->toBeEmpty();
});
