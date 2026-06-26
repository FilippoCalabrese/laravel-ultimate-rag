<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;
use Sellinnate\RagEngine\Support\Vectors;

/**
 * SQL-backed vector store (the `pgvector` driver) for small tenants / on-prem
 * (FR-VS-02). Vectors are stored as JSON and scored with a filtered brute-force
 * scan in PHP — correct and portable (works on Postgres/Neon, MySQL, SQLite).
 *
 * This is the "small scale" tier; native pgvector ANN indexing is a future
 * optimisation. Tenant scoping is pushed down to SQL (`tenant_id = ?`); richer
 * metadata operators are applied in PHP via {@see MetadataMatcher}.
 */
final class DatabaseVectorStore implements VectorStore
{
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly string $table = 'rag_vectors',
        private readonly string $namespacesTable = 'rag_vector_namespaces',
    ) {}

    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void
    {
        if (! in_array($metric, ['cosine', 'dot', 'l2'], true)) {
            throw new RagException("Unsupported distance metric [{$metric}].");
        }

        $existing = $this->namespaces()->where('namespace', $namespace)->first();

        if ($existing !== null) {
            if ((int) $existing->dimensions !== $dimensions && $this->count($namespace) > 0) {
                throw new RagException(
                    "Namespace [{$namespace}] already has {$existing->dimensions} dimensions; cannot change to {$dimensions} without re-embedding."
                );
            }

            $this->namespaces()->where('namespace', $namespace)->update(['dimensions' => $dimensions, 'metric' => $metric]);

            return;
        }

        $this->namespaces()->insert(['namespace' => $namespace, 'dimensions' => $dimensions, 'metric' => $metric]);
    }

    public function namespaceExists(string $namespace): bool
    {
        return $this->namespaces()->where('namespace', $namespace)->exists();
    }

    public function deleteNamespace(string $namespace): void
    {
        $this->rows()->where('namespace', $namespace)->delete();
        $this->namespaces()->where('namespace', $namespace)->delete();
    }

    public function upsert(string $namespace, array $records): void
    {
        $config = $this->namespaces()->where('namespace', $namespace)->first();
        $dimensions = $config !== null ? (int) $config->dimensions : null;

        foreach ($records as $record) {
            if ($dimensions !== null && $record->dimensions() !== $dimensions) {
                throw new RagException(
                    "Vector dimension mismatch in [{$namespace}]: expected {$dimensions}, got {$record->dimensions()}."
                );
            }

            $this->rows()->updateOrInsert(
                ['namespace' => $namespace, 'id' => $record->id],
                [
                    'tenant_id' => $record->tenantId(),
                    'vector' => json_encode($record->vector, JSON_THROW_ON_ERROR),
                    'metadata' => json_encode($record->metadata, JSON_THROW_ON_ERROR),
                ],
            );
        }
    }

    public function search(string $namespace, array $vector, RetrievalQuery $query): array
    {
        $config = $this->namespaces()->where('namespace', $namespace)->first();

        if ($config === null) {
            return [];
        }

        if (count($vector) !== (int) $config->dimensions) {
            throw new RagException(
                'Query vector has '.count($vector)." dimensions, expected {$config->dimensions} for [{$namespace}]."
            );
        }

        $metric = (string) $config->metric;
        $filters = $query->filters;
        if ($query->tenantId !== null) {
            $filters['tenant_id'] = $query->tenantId;
        }

        // Push tenant scoping down to SQL; richer operators are matched in PHP.
        $builder = $this->rows()->where('namespace', $namespace);
        if ($query->tenantId !== null) {
            $builder->where('tenant_id', $query->tenantId);
        }

        $scored = [];

        foreach ($builder->get() as $row) {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode((string) $row->metadata, true) ?: [];

            if (! MetadataMatcher::matches($metadata, $filters)) {
                continue;
            }

            /** @var list<float> $stored */
            $stored = array_map('floatval', json_decode((string) $row->vector, true) ?: []);
            $score = $this->score($vector, $stored, $metric);

            if ($query->scoreThreshold !== null && $score < $query->scoreThreshold) {
                continue;
            }

            $scored[] = new SearchHit(
                id: (string) $row->id,
                score: $score,
                content: (string) ($metadata['content'] ?? ''),
                metadata: $metadata,
                documentId: isset($metadata['document_id']) ? (string) $metadata['document_id'] : null,
                chunkId: isset($metadata['chunk_id']) ? (string) $metadata['chunk_id'] : null,
                vector: $stored,
            );
        }

        usort($scored, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, max(0, $query->topK));
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->rows()->where('namespace', $namespace)->whereIn('id', $ids)->delete();
    }

    public function deleteByFilter(string $namespace, array $filter): void
    {
        $builder = $this->rows()->where('namespace', $namespace);

        // Fast path: a single scalar filter maps to a column or a JSON match.
        if (isset($filter['tenant_id']) && ! is_array($filter['tenant_id']) && count($filter) === 1) {
            $builder->where('tenant_id', $filter['tenant_id'])->delete();

            return;
        }

        $ids = [];
        foreach ($builder->get() as $row) {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode((string) $row->metadata, true) ?: [];
            if (MetadataMatcher::matches($metadata, $filter)) {
                $ids[] = (string) $row->id;
            }
        }

        $this->delete($namespace, $ids);
    }

    public function count(string $namespace): int
    {
        return $this->rows()->where('namespace', $namespace)->count();
    }

    public function name(): string
    {
        return 'pgvector';
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function score(array $a, array $b, string $metric): float
    {
        return match ($metric) {
            'cosine' => Vectors::cosine($a, $b),
            'dot' => Vectors::dot($a, $b),
            'l2' => 1.0 / (1.0 + Vectors::euclidean($a, $b)),
            default => throw new RagException("Unsupported distance metric [{$metric}]."),
        };
    }

    private function rows(): Builder
    {
        return $this->db->connection($this->connection)->table($this->table);
    }

    private function namespaces(): Builder
    {
        return $this->db->connection($this->connection)->table($this->namespacesTable);
    }
}
