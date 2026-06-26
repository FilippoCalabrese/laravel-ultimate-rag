<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Exceptions\EmbeddingException;

/**
 * Shared behaviour for HTTP embedding providers: request execution, vector
 * extraction, dimensional validation (FR-EM-10) and cost computation (FR-EM-07).
 */
abstract class HttpEmbedder implements Embedder
{
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly string $model,
        protected readonly int $dimensions,
        protected readonly string $baseUrl,
        protected readonly ?string $apiKey = null,
        protected readonly float $costPer1kTokens = 0.0,
    ) {}

    public function embed(array $texts): EmbeddingResponse
    {
        if ($texts === []) {
            return new EmbeddingResponse([], $this->model, $this->dimensions, Usage::zero());
        }

        $response = $this->request()->post($this->endpoint(), $this->payload($texts));

        if (! $response->successful()) {
            // 429/5xx are retryable; 4xx (auth/bad-request) are not (FR-EM-06).
            throw EmbeddingException::fromStatus($this->name(), $response->status());
        }

        $vectors = $this->extractVectors($response->json());

        // Guard against partial responses: a vector/chunk count mismatch would
        // silently zip embeddings to the wrong chunks downstream (index corruption).
        if (count($vectors) !== count($texts)) {
            throw new EmbeddingException(
                sprintf(
                    'Embedding provider [%s] returned %d vectors for %d inputs.',
                    $this->name(),
                    count($vectors),
                    count($texts),
                ),
                retryable: false,
            );
        }

        $tokens = $this->extractTokens($response->json(), $texts);

        return new EmbeddingResponse(
            vectors: $vectors,
            model: $this->model,
            dimensions: $this->dimensions,
            usage: new Usage(tokens: $tokens, cost: $this->cost($tokens)),
        );
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->model;
    }

    abstract protected function name(): string;

    abstract protected function endpoint(): string;

    /**
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    abstract protected function payload(array $texts): array;

    /**
     * @param  mixed  $json
     * @return list<list<float>>
     */
    abstract protected function extractVectors($json): array;

    /**
     * @param  mixed  $json
     * @param  list<string>  $texts
     */
    abstract protected function extractTokens($json, array $texts): int;

    protected function request(): PendingRequest
    {
        $request = $this->http->baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout(30);

        return $this->apiKey !== null && $this->apiKey !== ''
            ? $request->withToken($this->apiKey)
            : $request;
    }

    protected function cost(int $tokens): float
    {
        return $tokens / 1000 * $this->costPer1kTokens;
    }

    /**
     * Fallback token estimate when the provider does not report usage.
     *
     * @param  list<string>  $texts
     */
    protected function estimateTokens(array $texts): int
    {
        return array_sum(array_map(static fn (string $t): int => (int) ceil(mb_strlen($t) / 4), $texts));
    }
}
