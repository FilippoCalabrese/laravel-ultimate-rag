<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Embedding\RetryingEmbedder;

/**
 * A counting embedder to observe how many times the underlying provider is hit.
 */
function countingEmbedder(int &$calls): Embedder
{
    return new class($calls) implements Embedder
    {
        public function __construct(private int &$calls) {}

        public function embed(array $texts): EmbeddingResponse
        {
            $this->calls++;
            $vectors = array_map(fn (string $t) => [(float) strlen($t), 1.0, 0.0], array_values($texts));

            return new EmbeddingResponse($vectors, 'm', 3, new Usage(tokens: count($texts) * 10, cost: 0.01));
        }

        public function embedOne(string $text): EmbeddingResponse
        {
            return $this->embed([$text]);
        }

        public function dimensions(): int
        {
            return 3;
        }

        public function model(): string
        {
            return 'm';
        }
    };
}

it('CachingEmbedder serves repeated text from cache with zero usage (FR-EM-05)', function () {
    $calls = 0;
    $cache = new Repository(new ArrayStore);
    $embedder = new CachingEmbedder(countingEmbedder($calls), $cache);

    $first = $embedder->embed(['hello', 'world']);
    expect($calls)->toBe(1)->and($first->usage->tokens)->toBe(20);

    $second = $embedder->embed(['hello', 'world']);
    expect($calls)->toBe(1) // no new provider call
        ->and($second->usage->tokens)->toBe(0) // cache hits cost nothing
        ->and($second->vectorAt(0))->toBe($first->vectorAt(0));
});

it('CachingEmbedder only embeds the cache-missed texts', function () {
    $calls = 0;
    $cache = new Repository(new ArrayStore);
    $embedder = new CachingEmbedder(countingEmbedder($calls), $cache);

    $embedder->embed(['a']);
    $mixed = $embedder->embed(['a', 'b']); // 'a' cached, 'b' new

    expect($calls)->toBe(2)
        ->and($mixed)->toHaveCount(2)
        ->and($mixed->usage->tokens)->toBe(10); // only 'b'
});

it('RetryingEmbedder retries on failure then succeeds (FR-EM-06)', function () {
    $attempts = 0;
    $fragile = new class($attempts) implements Embedder
    {
        public function __construct(private int &$attempts) {}

        public function embed(array $texts): EmbeddingResponse
        {
            $this->attempts++;
            if ($this->attempts < 3) {
                throw new RuntimeException('transient');
            }

            return new EmbeddingResponse([[0.1, 0.2, 0.3]], 'm', 3, Usage::zero());
        }

        public function embedOne(string $text): EmbeddingResponse
        {
            return $this->embed([$text]);
        }

        public function dimensions(): int
        {
            return 3;
        }

        public function model(): string
        {
            return 'm';
        }
    };

    $embedder = new RetryingEmbedder($fragile, maxAttempts: 3, sleeper: fn () => null);
    $response = $embedder->embed(['x']);

    expect($attempts)->toBe(3)->and($response)->toHaveCount(1);
});

it('RetryingEmbedder gives up after max attempts', function () {
    $alwaysFails = new class implements Embedder
    {
        public function embed(array $texts): EmbeddingResponse
        {
            throw new RuntimeException('permanent');
        }

        public function embedOne(string $text): EmbeddingResponse
        {
            return $this->embed([$text]);
        }

        public function dimensions(): int
        {
            return 3;
        }

        public function model(): string
        {
            return 'm';
        }
    };

    (new RetryingEmbedder($alwaysFails, maxAttempts: 2, sleeper: fn () => null))->embed(['x']);
})->throws(RuntimeException::class, 'permanent');

it('decorators expose the inner model and dimensions', function () {
    $cache = new Repository(new ArrayStore);
    $inner = new FakeEmbedder(dimensions: 8);

    expect((new CachingEmbedder($inner, $cache))->dimensions())->toBe(8)
        ->and((new RetryingEmbedder($inner))->model())->toBe('fake-embed-v1');
});
