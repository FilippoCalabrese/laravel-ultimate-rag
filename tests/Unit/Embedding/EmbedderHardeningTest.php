<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\MistralEmbedder;
use Sellinnate\RagEngine\Embedding\RetryingEmbedder;
use Sellinnate\RagEngine\Exceptions\EmbeddingException;

it('HttpEmbedder rejects a partial response (#vectors != #inputs) (C2)', function () {
    $http = new HttpFactory;
    $http->fake(['*/embeddings' => $http->response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

    $embedder = new MistralEmbedder($http, 'm', 3, 'https://api.mistral.ai/v1');
    $embedder->embed(['t0', 't1', 't2']); // 3 inputs, 1 vector
})->throws(EmbeddingException::class, 'returned 1 vectors for 3 inputs');

it('CachingEmbedder isolates cache by provider identity (C3)', function () {
    $cache = new Repository(new ArrayStore);

    $provA = makeFixedEmbedder([1.0, 0.0, 0.0]);
    $provB = makeFixedEmbedder([9.0, 0.0, 0.0]);

    $a = new CachingEmbedder($provA, $cache, identity: 'provider-a');
    $b = new CachingEmbedder($provB, $cache, identity: 'provider-b');

    $a->embed(['hello']);
    $fromB = $b->embed(['hello']);

    // B must NOT receive A's cached vector.
    expect($fromB->vectorAt(0))->toBe([9.0, 0.0, 0.0]);
});

it('CachingEmbedder embeds a duplicate within a batch only once (H1)', function () {
    $calls = 0;
    $embedded = [];
    $inner = new class($calls, $embedded) implements Embedder
    {
        /** @param array<int, string> $embedded */
        public function __construct(public int &$calls, public array &$embedded) {}

        public function embed(array $texts): EmbeddingResponse
        {
            $this->calls++;
            $this->embedded = array_values($texts);

            return new EmbeddingResponse(
                array_map(fn (string $t) => [(float) strlen($t), 0.0, 0.0], array_values($texts)),
                'm',
                3,
                new Usage(tokens: count($texts) * 10),
            );
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

    $caching = new CachingEmbedder($inner, new Repository(new ArrayStore));
    $response = $caching->embed(['dup', 'dup', 'other']);

    expect($inner->embedded)->toBe(['dup', 'other']) // 'dup' embedded once
        ->and($response)->toHaveCount(3)
        ->and($response->vectorAt(0))->toBe($response->vectorAt(1)) // both 'dup' positions identical
        ->and($response->usage->tokens)->toBe(20); // 2 unique texts
});

it('RetryingEmbedder does NOT retry a non-retryable error (H2)', function () {
    $attempts = 0;
    $inner = new class($attempts) implements Embedder
    {
        public function __construct(public int &$attempts) {}

        public function embed(array $texts): EmbeddingResponse
        {
            $this->attempts++;
            throw EmbeddingException::fromStatus('m', 401); // auth error, non-retryable
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

    try {
        (new RetryingEmbedder($inner, maxAttempts: 5, sleeper: fn () => null))->embed(['x']);
    } catch (EmbeddingException) {
        // expected
    }

    expect($inner->attempts)->toBe(1); // tried exactly once, no wasted retries
});

it('RetryingEmbedder DOES retry a retryable 503 (H2)', function () {
    $attempts = 0;
    $inner = new class($attempts) implements Embedder
    {
        public function __construct(public int &$attempts) {}

        public function embed(array $texts): EmbeddingResponse
        {
            $this->attempts++;
            if ($this->attempts < 2) {
                throw EmbeddingException::fromStatus('m', 503);
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

    (new RetryingEmbedder($inner, maxAttempts: 3, sleeper: fn () => null))->embed(['x']);

    expect($inner->attempts)->toBe(2);
});

function makeFixedEmbedder(array $vector): Embedder
{
    return new class($vector) implements Embedder
    {
        /** @param list<float> $vector */
        public function __construct(private array $vector) {}

        public function embed(array $texts): EmbeddingResponse
        {
            return new EmbeddingResponse(
                array_map(fn () => $this->vector, array_values($texts)),
                'shared-model',
                count($this->vector),
                Usage::zero(),
            );
        }

        public function embedOne(string $text): EmbeddingResponse
        {
            return $this->embed([$text]);
        }

        public function dimensions(): int
        {
            return count($this->vector);
        }

        public function model(): string
        {
            return 'shared-model';
        }
    };
}
