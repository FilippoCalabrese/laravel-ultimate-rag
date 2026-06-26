<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Deterministic in-process vector store (FR-VS-03, NFR-TE-02).
 *
 * Implements idempotent upsert/delete (FR-VS-12), metadata filtering
 * (FR-VS-08), configurable distance metrics (FR-VS-11) and namespace isolation
 * (FR-VS-09). Not for production scale — it is the reference/test backend.
 */
final class InMemoryVectorStore implements VectorStore
{
    /**
     * namespace => [ id => ['vector' => list<float>, 'metadata' => array] ]
     *
     * @var array<string, array<string, array{vector: list<float>, metadata: array<string, mixed>}>>
     */
    private array $store = [];

    /** @var array<string, array{dimensions: int, metric: string}> */
    private array $config = [];

    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void
    {
        if (! in_array($metric, ['cosine', 'dot', 'l2'], true)) {
            throw new RagException("Unsupported distance metric [{$metric}].");
        }

        // Changing dimensions on a populated namespace would silently corrupt
        // scoring (mixed-dimension vectors). Reject it — re-embedding migration
        // (FR-EM-09) is an explicit, separate flow.
        $existing = $this->config[$namespace]['dimensions'] ?? null;
        if ($existing !== null && $existing !== $dimensions && ($this->store[$namespace] ?? []) !== []) {
            throw new RagException(
                "Namespace [{$namespace}] already has {$existing} dimensions; cannot change to {$dimensions} without re-embedding."
            );
        }

        if (! isset($this->store[$namespace])) {
            $this->store[$namespace] = [];
        }

        $this->config[$namespace] = ['dimensions' => $dimensions, 'metric' => $metric];
    }

    public function namespaceExists(string $namespace): bool
    {
        return isset($this->store[$namespace]);
    }

    public function deleteNamespace(string $namespace): void
    {
        unset($this->store[$namespace], $this->config[$namespace]);
    }

    public function upsert(string $namespace, array $records): void
    {
        $this->ensureNamespace($namespace);
        $dimensions = $this->config[$namespace]['dimensions'] ?? null;

        foreach ($records as $record) {
            if ($dimensions !== null && $record->dimensions() !== $dimensions) {
                throw new RagException(
                    "Vector dimension mismatch in [{$namespace}]: expected {$dimensions}, got {$record->dimensions()}."
                );
            }

            // Idempotent: same id overwrites in place (FR-VS-12).
            $this->store[$namespace][$record->id] = [
                'vector' => $record->vector,
                'metadata' => $record->metadata,
            ];
        }
    }

    /**
     * Low-level similarity search. This is a primitive: like a raw SQL table it
     * applies only the filters it is given. Tenant isolation is NOT implicit
     * here — a query with a null tenantId spans all tenants by design. Mandatory
     * fail-closed tenant scoping is the job of the retrieval layer, which always
     * injects the tenant from {@see TenantContext}.
     */
    public function search(string $namespace, array $vector, RetrievalQuery $query): array
    {
        if (! $this->namespaceExists($namespace)) {
            return [];
        }

        $metric = $this->config[$namespace]['metric'] ?? 'cosine';
        $expectedDims = $this->config[$namespace]['dimensions'] ?? null;

        if ($expectedDims !== null && count($vector) !== $expectedDims) {
            throw new RagException(
                'Query vector has '.count($vector)." dimensions, expected {$expectedDims} for [{$namespace}]."
            );
        }

        $filters = $this->effectiveFilters($query);

        $scored = [];

        foreach ($this->store[$namespace] as $id => $entry) {
            if (! MetadataMatcher::matches($entry['metadata'], $filters)) {
                continue;
            }

            $score = $this->score($vector, $entry['vector'], $metric);

            if ($query->scoreThreshold !== null && $score < $query->scoreThreshold) {
                continue;
            }

            $scored[] = new SearchHit(
                id: $id,
                score: $score,
                content: (string) ($entry['metadata']['content'] ?? ''),
                metadata: $entry['metadata'],
                documentId: isset($entry['metadata']['document_id']) ? (string) $entry['metadata']['document_id'] : null,
                chunkId: isset($entry['metadata']['chunk_id']) ? (string) $entry['metadata']['chunk_id'] : null,
                vector: $entry['vector'],
            );
        }

        usort($scored, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, max(0, $query->topK));
    }

    public function delete(string $namespace, array $ids): void
    {
        if (! $this->namespaceExists($namespace)) {
            return;
        }

        foreach ($ids as $id) {
            unset($this->store[$namespace][$id]);
        }
    }

    public function deleteByFilter(string $namespace, array $filter): void
    {
        if (! $this->namespaceExists($namespace)) {
            return;
        }

        foreach ($this->store[$namespace] as $id => $entry) {
            if (MetadataMatcher::matches($entry['metadata'], $filter)) {
                unset($this->store[$namespace][$id]);
            }
        }
    }

    public function count(string $namespace): int
    {
        return count($this->store[$namespace] ?? []);
    }

    public function name(): string
    {
        return 'memory';
    }

    /**
     * Merge tenant scope into the explicit filters (FR-MT-02 scoping).
     *
     * @return array<string, mixed>
     */
    private function effectiveFilters(RetrievalQuery $query): array
    {
        $filters = $query->filters;

        if ($query->tenantId !== null) {
            $filters['tenant_id'] = $query->tenantId;
        }

        return $filters;
    }

    private function ensureNamespace(string $namespace): void
    {
        if (! isset($this->store[$namespace])) {
            $this->store[$namespace] = [];
        }
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function score(array $a, array $b, string $metric): float
    {
        return match ($metric) {
            'cosine' => $this->cosine($a, $b),
            'dot' => $this->dot($a, $b),
            // Normalize L2 distance to a positive higher-is-better score in (0,1]
            // so the convention matches every other metric and the Qdrant driver.
            'l2' => 1.0 / (1.0 + $this->euclidean($a, $b)),
            default => throw new RagException("Unsupported distance metric [{$metric}]."),
        };
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $this->dot($a, $b);
        $magA = sqrt($this->dot($a, $a));
        $magB = sqrt($this->dot($b, $b));

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / ($magA * $magB);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function euclidean(array $a, array $b): float
    {
        $sum = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $diff = $a[$i] - $b[$i];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
    }
}
