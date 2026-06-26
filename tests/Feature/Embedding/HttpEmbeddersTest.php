<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Embedding\MistralEmbedder;
use Sellinnate\RagEngine\Embedding\OllamaEmbedder;
use Sellinnate\RagEngine\Exceptions\EmbeddingException;
use Sellinnate\RagEngine\Exceptions\RagException;

it('MistralEmbedder embeds via the EU API and computes cost (FR-EM-01/07)', function () {
    Http::fake(['*/embeddings' => Http::response([
        'data' => [
            ['embedding' => [0.1, 0.2, 0.3]],
            ['embedding' => [0.4, 0.5, 0.6]],
        ],
        'usage' => ['total_tokens' => 1000],
    ])]);

    $embedder = new MistralEmbedder(app(HttpFactory::class), 'mistral-embed', 3, 'https://api.mistral.ai/v1', 'key', costPer1kTokens: 0.1);
    $response = $embedder->embed(['a', 'b']);

    expect($response)->toHaveCount(2)
        ->and($response->vectorAt(0))->toBe([0.1, 0.2, 0.3])
        ->and($response->usage->tokens)->toBe(1000)
        ->and($response->usage->cost)->toBe(0.1)
        ->and($response->model)->toBe('mistral-embed');
});

it('OllamaEmbedder embeds via the local API (FR-EM-02)', function () {
    Http::fake(['*/api/embed' => Http::response([
        'embeddings' => [[0.1, 0.2], [0.3, 0.4]],
        'prompt_eval_count' => 12,
    ])]);

    $embedder = new OllamaEmbedder(app(HttpFactory::class), 'nomic-embed-text', 2, 'http://localhost:11434');
    $response = $embedder->embed(['x', 'y']);

    expect($response)->toHaveCount(2)
        ->and($response->vectorAt(1))->toBe([0.3, 0.4])
        ->and($response->usage->tokens)->toBe(12)
        ->and($response->usage->cost)->toBe(0.0);
});

it('OllamaEmbedder estimates tokens when prompt_eval_count is absent', function () {
    Http::fake(['*/api/embed' => Http::response(['embeddings' => [[0.1, 0.2]]])]);

    $embedder = new OllamaEmbedder(app(HttpFactory::class), 'nomic-embed-text', 2, 'http://localhost:11434');
    $response = $embedder->embed(['some text to estimate']);

    expect($response->usage->tokens)->toBeGreaterThan(0);
});

it('OllamaEmbedder throws on a provider error', function () {
    Http::fake(['*/api/embed' => Http::response('down', 503)]);

    $embedder = new OllamaEmbedder(app(HttpFactory::class), 'm', 2, 'http://localhost:11434');
    $embedder->embed(['a']);
})->throws(EmbeddingException::class);

it('returns an empty response for empty input without calling the API', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $embedder = new MistralEmbedder(app(HttpFactory::class), 'm', 3, 'https://api.mistral.ai/v1');

    expect($embedder->embed([]))->toHaveCount(0);
    Http::assertNothingSent();
});

it('throws a RagException on a provider error', function () {
    Http::fake(['*/embeddings' => Http::response('boom', 500)]);

    $embedder = new MistralEmbedder(app(HttpFactory::class), 'm', 3, 'https://api.mistral.ai/v1');
    $embedder->embed(['a']);
})->throws(RagException::class, 'failed');

it('falls back to an estimated token count when usage is absent', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

    $embedder = new MistralEmbedder(app(HttpFactory::class), 'm', 3, 'https://api.mistral.ai/v1');
    $response = $embedder->embed(['some text']);

    expect($response->usage->tokens)->toBeGreaterThan(0);
});

it('sends the bearer token when an api key is configured', function () {
    Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

    $embedder = new MistralEmbedder(app(HttpFactory::class), 'm', 3, 'https://api.mistral.ai/v1', 'secret-key');
    $embedder->embed(['a']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-key'));
});
