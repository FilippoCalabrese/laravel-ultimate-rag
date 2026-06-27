<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\VectorStore;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Support\MetadataMatcher;

/**
 * Native pgvector store (FR-VS-02) — real ANN search inside Postgres.
 *
 * Vectors are stored in a `vector(D)` column and queried with pgvector's distance
 * operators (`<=>` cosine, `<->` L2, `<#>` inner product), so scoring and top-k
 * selection run in the database against an HNSW index — not in PHP. The schema
 * (extension + table + index) is created lazily on first use.
 *
 * Single fixed embedding dimension per store (one embedding model per
 * deployment): mixed dimensions need separate stores, the portable `database`
 * driver, or Qdrant.
 */
final class PgVectorStore implements VectorStore
{
    /** metric => [operator, index opclass] */
    private const METRICS = [
        'cosine' => ['<=>', 'vector_cosine_ops'],
        'l2' => ['<->', 'vector_l2_ops'],
        'dot' => ['<#>', 'vector_ip_ops'],
    ];

    private bool $schemaReady = false;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly ?string $connection = null,
        private readonly string $table = 'rag_pgvectors',
        private readonly int $dimensions = 1536,
        private readonly string $metric = 'cosine',
        private readonly string $index = 'hnsw',
    ) {}

    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void
    {
        if (! isset(self::METRICS[$metric])) {
            throw new RagException("Unsupported distance metric [{$metric}].");
        }

        if ($metric !== $this->metric) {
            throw new RagException(
                "pgvector store is configured for the [{$this->metric}] metric; namespace [{$namespace}] requested [{$metric}]."
            );
        }

        if ($dimensions !== $this->dimensions) {
            throw new RagException(
                "pgvector store is fixed to {$this->dimensions} dimensions; namespace [{$namespace}] needs {$dimensions}. "
                .'Set rag-engine.vector_stores.pgvector.dimensions to match your embedding model, or use the [database] / [qdrant] driver for mixed dimensions.'
            );
        }

        $this->ensureSchema();
    }

    public function namespaceExists(string $namespace): bool
    {
        return $this->tableExists();
    }

    public function deleteNamespace(string $namespace): void
    {
        if ($this->tableExists()) {
            $this->conn()->table($this->table)->where('namespace', $namespace)->delete();
        }
    }

    public function upsert(string $namespace, array $records): void
    {
        if ($records === []) {
            return;
        }

        $this->ensureSchema();

        $sql = "INSERT INTO {$this->table} (id, namespace, tenant_id, embedding, metadata, content) "
            .'VALUES (?, ?, ?, ?::vector, ?::jsonb, ?) '
            .'ON CONFLICT (id) DO UPDATE SET namespace = EXCLUDED.namespace, tenant_id = EXCLUDED.tenant_id, '
            .'embedding = EXCLUDED.embedding, metadata = EXCLUDED.metadata, content = EXCLUDED.content';

        $conn = $this->conn();
        $conn->transaction(function () use ($conn, $sql, $namespace, $records): void {
            foreach ($records as $record) {
                if ($record->dimensions() !== $this->dimensions) {
                    throw new RagException(
                        "Vector for [{$record->id}] has {$record->dimensions()} dimensions, expected {$this->dimensions}."
                    );
                }

                $conn->statement($sql, [
                    $record->id,
                    $namespace,
                    $record->tenantId(),
                    $this->toVector($record->vector),
                    json_encode($record->metadata, JSON_THROW_ON_ERROR),
                    is_string($record->metadata['content'] ?? null) ? $record->metadata['content'] : '',
                ]);
            }
        });
    }

    public function search(string $namespace, array $vector, RetrievalQuery $query): array
    {
        if (! $this->tableExists() || count($vector) !== $this->dimensions) {
            return [];
        }

        [$operator] = self::METRICS[$this->metric];
        $vectorParam = $this->toVector($vector);

        // Over-fetch so rich metadata operators (applied in PHP) still fill topK.
        $limit = max($query->topK * 5, $query->topK);

        $sql = "SELECT id, metadata, content, embedding::text AS emb, (embedding {$operator} ?::vector) AS distance "
            ."FROM {$this->table} WHERE namespace = ?";
        $bindings = [$vectorParam, $namespace];

        if ($query->tenantId !== null) {
            $sql .= ' AND tenant_id = ?';
            $bindings[] = $query->tenantId;
        }

        $sql .= " ORDER BY embedding {$operator} ?::vector LIMIT ?";
        $bindings[] = $vectorParam;
        $bindings[] = $limit;

        $filters = $query->filters;
        if ($query->tenantId !== null) {
            $filters['tenant_id'] = $query->tenantId;
        }

        $hits = [];
        foreach ($this->conn()->select($sql, $bindings) as $row) {
            /** @var array<string, mixed> $metadata */
            $metadata = json_decode((string) $row->metadata, true) ?: [];

            if (! MetadataMatcher::matches($metadata, $filters)) {
                continue;
            }

            $score = $this->scoreFromDistance((float) $row->distance);

            if ($query->scoreThreshold !== null && $score < $query->scoreThreshold) {
                continue;
            }

            $hits[] = new SearchHit(
                id: (string) $row->id,
                score: $score,
                content: (string) ($metadata['content'] ?? ''),
                metadata: $metadata,
                documentId: isset($metadata['document_id']) ? (string) $metadata['document_id'] : null,
                chunkId: isset($metadata['chunk_id']) ? (string) $metadata['chunk_id'] : null,
                vector: $this->parseVector((string) $row->emb),
            );

            if (count($hits) >= $query->topK) {
                break;
            }
        }

        return $hits;
    }

    public function delete(string $namespace, array $ids): void
    {
        if ($ids === [] || ! $this->tableExists()) {
            return;
        }

        $this->conn()->table($this->table)->where('namespace', $namespace)->whereIn('id', $ids)->delete();
    }

    public function deleteByFilter(string $namespace, array $filter): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $builder = $this->conn()->table($this->table)->where('namespace', $namespace);

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
        if (! $this->tableExists()) {
            return 0;
        }

        return $this->conn()->table($this->table)->where('namespace', $namespace)->count();
    }

    public function name(): string
    {
        return 'pgvector';
    }

    private function ensureSchema(): void
    {
        if ($this->schemaReady) {
            return;
        }

        [, $opclass] = self::METRICS[$this->metric];
        $conn = $this->conn();

        $conn->statement('CREATE EXTENSION IF NOT EXISTS vector');
        $conn->statement(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            .'id text PRIMARY KEY, '
            .'namespace text NOT NULL, '
            .'tenant_id text, '
            ."embedding vector({$this->dimensions}) NOT NULL, "
            .'metadata jsonb, '
            .'content text'
            .')'
        );
        $conn->statement("CREATE INDEX IF NOT EXISTS {$this->table}_ns_idx ON {$this->table} (namespace, tenant_id)");
        $conn->statement(
            "CREATE INDEX IF NOT EXISTS {$this->table}_emb_idx ON {$this->table} USING {$this->index} (embedding {$opclass})"
        );

        $this->schemaReady = true;
    }

    private function tableExists(): bool
    {
        if ($this->schemaReady) {
            return true;
        }

        $exists = $this->conn()->selectOne('SELECT to_regclass(?) AS reg', [$this->table]);

        return $exists !== null && ($exists->reg ?? null) !== null;
    }

    /**
     * @param  list<float>  $vector
     */
    private function toVector(array $vector): string
    {
        return '['.implode(',', array_map(static fn (float $v): string => (string) $v, $vector)).']';
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $text): array
    {
        $trimmed = trim($text, "[] \t\n\r");
        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    private function scoreFromDistance(float $distance): float
    {
        return match ($this->metric) {
            'cosine' => 1.0 - $distance,    // cosine distance in [0,2] → similarity
            'l2' => 1.0 / (1.0 + $distance),
            'dot' => -$distance,            // <#> returns the negative inner product
            default => -$distance,
        };
    }

    private function conn(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
