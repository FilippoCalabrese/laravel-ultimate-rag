<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use Sellinnate\RagEngine\Data\SearchHit;

/**
 * Fluent retrieval builder (FR-RT-06/08): abstracts the driver behind a chainable
 * API. Terminal methods ({@see get()}, {@see first()}) run the {@see Retriever}.
 */
final class SearchBuilder
{
    private int $topK = 10;

    private ?float $threshold = null;

    /** @var array<string, mixed> */
    private array $filters = [];

    private string $namespace = 'documents';

    private bool $hybrid = false;

    private bool $mmr = false;

    private float $mmrLambda = 0.5;

    private bool $rerank = false;

    private ?string $rerankerName = null;

    private bool $expandParents = false;

    private ?int $contextBudgetTokens = null;

    private bool $dedup = true;

    private ?int $fetchK = null;

    private ?string $embedder = null;

    private ?string $store = null;

    private bool $expandQueries = false;

    private int $queryVariations = 3;

    private ?string $llm = null;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly string $text,
    ) {}

    public function topK(int $topK): self
    {
        $this->topK = $topK;

        return $this;
    }

    public function threshold(float $threshold): self
    {
        $this->threshold = $threshold;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filter(array $filters): self
    {
        $this->filters = [...$this->filters, ...$filters];

        return $this;
    }

    public function where(string $key, mixed $value): self
    {
        $this->filters[$key] = $value;

        return $this;
    }

    public function namespace(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function hybrid(bool $hybrid = true): self
    {
        $this->hybrid = $hybrid;

        return $this;
    }

    public function mmr(float $lambda = 0.5): self
    {
        $this->mmr = true;
        $this->mmrLambda = $lambda;

        return $this;
    }

    public function rerank(?string $reranker = null): self
    {
        $this->rerank = true;
        $this->rerankerName = $reranker;

        return $this;
    }

    public function expandParents(bool $expand = true): self
    {
        $this->expandParents = $expand;

        return $this;
    }

    public function contextBudget(int $tokens): self
    {
        $this->contextBudgetTokens = $tokens;

        return $this;
    }

    public function dedup(bool $dedup = true): self
    {
        $this->dedup = $dedup;

        return $this;
    }

    public function fetch(int $k): self
    {
        $this->fetchK = $k;

        return $this;
    }

    public function using(string $embedder): self
    {
        $this->embedder = $embedder;

        return $this;
    }

    public function store(string $store): self
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Expand the query into several LLM-generated phrasings, retrieve each and
     * fuse the results (multi-query retrieval, FR-QT-01). Improves recall on
     * differently-worded questions. Requires a configured LLM; with the `null`
     * LLM it gracefully no-ops (runs the original query only).
     */
    public function expandQueries(int $variations = 3, ?string $llm = null): self
    {
        $this->expandQueries = true;
        $this->queryVariations = max(1, $variations);
        $this->llm = $llm;

        return $this;
    }

    public function toRequest(): SearchRequest
    {
        return new SearchRequest(
            text: $this->text,
            topK: $this->topK,
            scoreThreshold: $this->threshold,
            filters: $this->filters,
            namespace: $this->namespace,
            hybrid: $this->hybrid,
            mmr: $this->mmr,
            mmrLambda: $this->mmrLambda,
            rerank: $this->rerank,
            rerankerName: $this->rerankerName,
            expandParents: $this->expandParents,
            contextBudgetTokens: $this->contextBudgetTokens,
            dedup: $this->dedup,
            fetchK: $this->fetchK,
            embedder: $this->embedder,
            store: $this->store,
            expandQueries: $this->expandQueries,
            queryVariations: $this->queryVariations,
            llm: $this->llm,
        );
    }

    /**
     * @return list<SearchHit>
     */
    public function get(): array
    {
        return $this->retriever->retrieve($this->toRequest());
    }

    public function first(): ?SearchHit
    {
        return $this->get()[0] ?? null;
    }

    public function count(): int
    {
        return count($this->get());
    }
}
