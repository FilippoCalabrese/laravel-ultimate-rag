<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\ProviderException;

/**
 * Base class for HTTP cross-encoder reranker drivers (FR-RR-01).
 *
 * Cohere and Jina expose a near-identical rerank API ({model, query, documents,
 * top_n} → {results: [{index, relevance_score}]}), so the request/response
 * handling lives here; subclasses only declare endpoint, auth and defaults.
 */
abstract class HttpReranker implements Reranker
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly string $model,
        protected readonly ?string $apiKey = null,
        protected readonly string $baseUrl = '',
        protected readonly array $options = [],
    ) {}

    abstract public function name(): string;

    abstract protected function endpoint(): string;

    public function rerank(string $query, array $hits, int $topK): array
    {
        if ($hits === [] || $topK <= 0) {
            return [];
        }

        $documents = array_map(static fn (SearchHit $hit): string => $hit->content, $hits);

        $response = $this->request()->post($this->endpoint(), [
            'model' => $this->model,
            'query' => $query,
            'documents' => $documents,
            'top_n' => $topK,
        ]);

        if ($response->failed()) {
            throw ProviderException::fromStatus($this->name(), $response->status(), $response->body());
        }

        return $this->mapResults($response->json(), $hits, $topK);
    }

    /**
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    protected function mapResults(mixed $json, array $hits, int $topK): array
    {
        $results = is_array($json) && isset($json['results']) && is_array($json['results'])
            ? $json['results']
            : [];

        $reranked = [];
        foreach ($results as $result) {
            if (! is_array($result) || ! isset($result['index'])) {
                continue;
            }

            $index = (int) $result['index'];
            if (! isset($hits[$index])) {
                continue;
            }

            $score = isset($result['relevance_score']) ? (float) $result['relevance_score'] : $hits[$index]->score;
            $reranked[] = $hits[$index]->withScore($score);
        }

        // Providers return results best-first, but sort defensively and truncate.
        usort($reranked, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($reranked, 0, $topK);
    }

    protected function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->timeout((int) ($this->options['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->withToken((string) $this->apiKey);
    }
}
