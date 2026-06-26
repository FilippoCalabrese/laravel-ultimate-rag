<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Retrieval;

use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Events\SearchPerformed;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Executes a {@see SearchRequest} end to end (FR-RT). Tenant scoping is
 * MANDATORY and fail-closed: scope is ALWAYS the ambient {@see TenantContext} —
 * a request cannot widen it. To search another tenant use the authorized
 * `Rag::forTenant()` (which sets the context and restores it after), never a
 * caller-supplied tenant on the query.
 */
final class Retriever
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorStoreManager $stores,
        private readonly RerankerManager $rerankers,
        private readonly TenantContext $tenant,
        private readonly Tokenizer $tokenizer,
        private readonly EnvelopeEncrypter $encrypter,
        private readonly Rrf $rrf = new Rrf,
        private readonly Mmr $mmr = new Mmr,
        private readonly KeywordScorer $keyword = new KeywordScorer,
    ) {}

    /**
     * @return list<SearchHit>
     */
    public function retrieve(SearchRequest $request): array
    {
        // Fail-closed tenant scoping: ALWAYS the ambient tenant, never the caller's.
        $tenantId = $this->tenant->id();
        $store = $this->stores->driver($request->store);
        $fetchK = $request->effectiveFetchK();

        $queryVector = $this->embedding->embed([$request->text], $request->embedder)->vectorAt(0);

        $query = new RetrievalQuery(
            text: $request->text,
            topK: $fetchK,
            filters: $request->filters,
            namespace: $request->namespace,
            tenantId: $tenantId,
        );

        $candidates = $store->search($request->namespace, $queryVector, $query);

        if ($request->hybrid) {
            $keywordRanking = $this->keyword->rank($request->text, $candidates, $fetchK);
            $candidates = $this->rrf->fuse([$candidates, $keywordRanking], $fetchK);
        }

        if ($request->rerank) {
            $candidates = $this->rerankers->driver($request->rerankerName)->rerank($request->text, $candidates, $fetchK);
        }

        // Filter the candidate POOL before the final top-k selection so unique,
        // above-threshold results backfill to topK (no under-filling).
        if ($request->scoreThreshold !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn (SearchHit $h): bool => $h->score >= $request->scoreThreshold,
            ));
        }

        if ($request->dedup) {
            $candidates = $this->dedupe($candidates);
        }

        $hits = $request->mmr
            ? $this->applyMmr($queryVector, $candidates, $request->topK, $request->mmrLambda)
            : array_slice($candidates, 0, max(0, $request->topK));

        if ($request->expandParents) {
            $hits = $this->expandParents($hits, $tenantId);
        }

        if ($request->contextBudgetTokens !== null) {
            $hits = $this->applyBudget($hits, $request->contextBudgetTokens);
        }

        event(new SearchPerformed($tenantId, $request->text, count($hits)));

        return $hits;
    }

    /**
     * @param  list<float>  $queryVector
     * @param  list<SearchHit>  $candidates
     * @return list<SearchHit>
     */
    private function applyMmr(array $queryVector, array $candidates, int $topK, float $lambda): array
    {
        $withVectors = [];
        foreach ($candidates as $hit) {
            if ($hit->vector !== null) {
                $withVectors[] = ['hit' => $hit, 'vector' => $hit->vector];
            }
        }

        // If the backend didn't return vectors, MMR can't run — fall back.
        if ($withVectors === []) {
            return array_slice($candidates, 0, $topK);
        }

        return $this->mmr->select($queryVector, $withVectors, $topK, $lambda);
    }

    /**
     * Result deduplication by content (FR-RR-03).
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function dedupe(array $hits): array
    {
        $seen = [];
        $out = [];

        foreach ($hits as $hit) {
            $key = hash('sha256', trim($hit->content));

            if ($hit->content !== '' && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $hit;
        }

        return $out;
    }

    /**
     * Parent-context expansion for small-to-big retrieval (FR-RT-07).
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function expandParents(array $hits, string $tenantId): array
    {
        return array_map(function (SearchHit $hit) use ($tenantId): SearchHit {
            $parentId = $hit->metadata['parent_chunk_id'] ?? null;

            if (! is_string($parentId)) {
                return $hit;
            }

            $parent = Chunk::query()->where('id', $parentId)->where('tenant_id', $tenantId)->first();

            if (! $parent instanceof Chunk || $parent->encrypted_content === null) {
                return $hit;
            }

            /** @var array<string, string> $payload */
            $payload = json_decode($parent->encrypted_content, true);
            $parentContent = $this->encrypter->decrypt(EncryptedPayload::fromArray($payload));

            return $hit->withMetadata(['parent_content' => $parentContent]);
        }, $hits);
    }

    /**
     * Token-aware context budget (FR-RR-04): keep hits until the budget is spent.
     *
     * @param  list<SearchHit>  $hits
     * @return list<SearchHit>
     */
    private function applyBudget(array $hits, int $budgetTokens): array
    {
        $spent = 0;
        $kept = [];

        foreach ($hits as $hit) {
            // Budget against the text that will actually be consumed: the expanded
            // parent content when present (FR-RT-07), else the chunk content.
            $consumed = isset($hit->metadata['parent_content']) && is_string($hit->metadata['parent_content'])
                ? $hit->metadata['parent_content']
                : $hit->content;

            $tokens = ($consumed === $hit->content && is_int($hit->metadata['token_count'] ?? null))
                ? $hit->metadata['token_count']
                : $this->tokenizer->count($consumed);

            if ($kept !== [] && $spent + $tokens > $budgetTokens) {
                break;
            }

            $kept[] = $hit;
            $spent += $tokens;
        }

        return $kept;
    }
}
