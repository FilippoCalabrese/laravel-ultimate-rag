<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

beforeEach(function () {
    $this->embedder = new FakeEmbedder(dimensions: 8, tokenizer: new ApproximateTokenizer);
});

it('returns one vector per input of the configured dimensionality', function () {
    $response = $this->embedder->embed(['alpha', 'beta', 'gamma']);

    expect($response)->toBeInstanceOf(EmbeddingResponse::class)
        ->and($response)->toHaveCount(3)
        ->and($response->dimensions)->toBe(8)
        ->and($response->vectorAt(0))->toHaveCount(8);
});

it('is deterministic: same text yields the same vector', function () {
    expect($this->embedder->embedOne('hello')->vectorAt(0))
        ->toBe($this->embedder->embedOne('hello')->vectorAt(0));
});

it('produces different vectors for different text', function () {
    expect($this->embedder->embedOne('hello')->vectorAt(0))
        ->not->toBe($this->embedder->embedOne('world')->vectorAt(0));
});

it('returns unit-normalized vectors', function () {
    $vector = $this->embedder->embedOne('normalize me')->vectorAt(0);
    $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $vector)));

    expect($magnitude)->toBeGreaterThan(0.99)->toBeLessThan(1.01);
});

it('tracks token usage', function () {
    $response = $this->embedder->embed(['some text here', 'more text']);

    expect($response->usage->tokens)->toBeGreaterThan(0)
        ->and($response->usage->cost)->toBe(0.0);
});

it('exposes model and dimensions', function () {
    expect($this->embedder->model())->toBe('fake-embed-v1')
        ->and($this->embedder->dimensions())->toBe(8);
});
