<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Indexing;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Events\ChunksEmbedded;
use Sellinnate\RagEngine\Events\DocumentIndexed;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;

/**
 * Persists chunks and indexes their vectors (bridges chunking → vector store).
 *
 * - Chunk text is envelope-encrypted at rest (FR-SEC-01); the plaintext lives in
 *   the vector store payload, which sits inside the tenant perimeter (FR-SEC-06).
 * - Re-indexing a document atomically replaces its prior chunks/vectors (FR-AF-05,
 *   FR-IN-08).
 * - Parent chunks (parent-child) are persisted for context expansion but only
 *   children are embedded/searched (FR-CH-07, FR-RT-07).
 */
final class Indexer
{
    public function __construct(
        private readonly EmbeddingService $embedding,
        private readonly VectorStoreManager $stores,
        private readonly EnvelopeEncrypter $encrypter,
        private readonly Repository $config,
        private readonly \Illuminate\Contracts\Cache\Repository $cache,
    ) {}

    /**
     * @param  list<TextChunk>  $chunks
     * @param  array<string, mixed>  $options
     */
    public function index(Document $document, array $chunks, array $options = []): int
    {
        // Serialize concurrent re-index of the same document so two runs cannot
        // orphan each other's vectors (B-M1). Best-effort: no-op if the cache
        // store doesn't provide atomic locks.
        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            // TTL generous enough to cover a slow embedding call so the lock can't
            // expire mid-operation and re-open the race it guards.
            return $store->lock('rag:index:'.$document->id, 600)
                ->block(15, fn (): int => $this->doIndex($document, $chunks, $options));
        }

        return $this->doIndex($document, $chunks, $options);
    }

    /**
     * @param  list<TextChunk>  $chunks
     * @param  array<string, mixed>  $options
     */
    private function doIndex(Document $document, array $chunks, array $options): int
    {
        $namespace = (string) ($options['namespace'] ?? $this->config->get('rag-engine.namespace', 'documents'));
        $previousNamespace = $document->indexed_namespace;
        $tenantId = $document->tenant_id;
        $store = $this->stores->driver($options['store'] ?? null);

        // May throw on a dimension change — before any mutation (fail-closed).
        $metric = (string) ($options['metric'] ?? $this->config->get('rag-engine.defaults.distance_metric', 'cosine'));
        $store->createNamespace($namespace, $this->embedding->embed([])->dimensions ?: 8, $metric);

        // Pre-generate stable ids so vectors and rows agree.
        $rowIds = [];
        foreach ($chunks as $chunk) {
            $rowIds[$chunk->index] = (string) Str::uuid();
        }

        $children = array_values(array_filter($chunks, fn (TextChunk $c): bool => ($c->metadata['is_parent'] ?? false) === false));

        // 1. Embed FIRST — the failure-prone step — before destroying anything.
        $response = $children === []
            ? null
            : $this->embedding->embed(array_map(static fn (TextChunk $c): string => $c->embeddableText(), $children), $options['embedder'] ?? null);

        // 2. Upsert the NEW vectors (new ids) before removing the old generation,
        //    so a failure here leaves the prior index intact (FR-AF-05).
        $records = $this->buildRecords($children, $rowIds, $response, $document, $tenantId, $namespace);
        if ($records !== []) {
            $store->upsert($namespace, $records);
        }

        // 3. Swap chunk rows in one transaction (old out, new in).
        $oldChunkIds = Chunk::where('document_id', $document->id)->pluck('id')->all();

        DB::transaction(function () use ($chunks, $children, $rowIds, $response, $document, $tenantId, $namespace, $options, $oldChunkIds): void {
            // Remove the previous generation's chunk rows AND their embedding
            // records (otherwise EmbeddingRecords orphan and grow unbounded).
            if ($oldChunkIds !== []) {
                EmbeddingRecord::whereIn('chunk_id', $oldChunkIds)->delete();
            }
            Chunk::where('document_id', $document->id)->delete();

            foreach ($chunks as $chunk) {
                Chunk::create([
                    'id' => $rowIds[$chunk->index],
                    'document_id' => $document->id,
                    'tenant_id' => $tenantId,
                    'encrypted_content' => json_encode($this->encrypter->encrypt($chunk->content, $tenantId)->toArray(), JSON_THROW_ON_ERROR),
                    'content' => null,
                    'position' => $chunk->index,
                    'offset' => $chunk->offset,
                    'metadata' => $chunk->metadata,
                    'parent_chunk_id' => $chunk->parentIndex !== null ? ($rowIds[$chunk->parentIndex] ?? null) : null,
                    'token_count' => $chunk->tokenCount,
                ]);
            }

            if ($response !== null) {
                foreach ($children as $child) {
                    EmbeddingRecord::create([
                        'chunk_id' => $rowIds[$child->index],
                        'tenant_id' => $tenantId,
                        'model' => $response->model,
                        'dimensions' => $response->dimensions,
                        'provider' => $options['embedder'] ?? 'default',
                        'vector_ref' => $namespace.':'.$rowIds[$child->index],
                        'cost' => 0,
                    ]);
                }
            }

            $document->forceFill(['status' => 'indexed', 'indexed_namespace' => $namespace])->save();
        });

        // 4. Now the new generation is committed — drop the old vectors from the
        //    namespace they actually lived in (which may differ from the new one).
        $oldNamespace = $previousNamespace ?? $namespace;
        if ($oldChunkIds !== []) {
            $store->delete($oldNamespace, array_values(array_map('strval', $oldChunkIds)));
        }
        // If the document moved to a different namespace, sweep any stragglers in
        // the old namespace so no plaintext vectors are orphaned (shred safety).
        if ($previousNamespace !== null && $previousNamespace !== $namespace && $store->namespaceExists($previousNamespace)) {
            $store->deleteByFilter($previousNamespace, ['document_id' => (string) $document->id]);
        }

        if ($children !== []) {
            event(new ChunksEmbedded((string) $document->id, $tenantId, count($children)));
        }
        event(new DocumentIndexed((string) $document->id, $tenantId, $namespace));

        return count($children);
    }

    /**
     * @param  list<TextChunk>  $children
     * @param  array<int, string>  $rowIds
     * @return list<VectorRecord>
     */
    private function buildRecords(array $children, array $rowIds, ?EmbeddingResponse $response, Document $document, string $tenantId, string $namespace): array
    {
        if ($response === null) {
            return [];
        }

        $provenance = $this->provenanceMetadata($document);

        $records = [];
        foreach ($children as $i => $child) {
            $records[] = new VectorRecord(
                id: $rowIds[$child->index],
                vector: $response->vectorAt($i),
                metadata: [
                    // Document-level provenance first (lowest priority) so a chunk
                    // is always traceable to its source — and the authoritative
                    // system keys below can never be shadowed by it.
                    ...$provenance,
                    ...$this->payloadMetadata($child),
                    'tenant_id' => $tenantId,
                    'document_id' => (string) $document->id,
                    'chunk_id' => $rowIds[$child->index],
                    'parent_chunk_id' => $child->parentIndex !== null ? ($rowIds[$child->parentIndex] ?? null) : null,
                    'content' => $child->content,
                    'is_parent' => false,
                ],
            );
        }

        return $records;
    }

    /**
     * Provenance written into every vector payload so any retrieved chunk traces
     * back to its origin (FR-RT-06): the source type, a human-readable reference
     * (URL / filename / logical key), and any explicit per-document propagation
     * (e.g. an Eloquent model's `embeddable_*` identity via `rag_vector_metadata`).
     *
     * @return array<string, mixed>
     */
    private function provenanceMetadata(Document $document): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = is_array($document->metadata) ? $document->metadata : [];

        $provenance = ['source_type' => $document->source_type];

        foreach (['url', 'filename', 'document_key', 'source_ref', 'key'] as $candidate) {
            if (isset($metadata[$candidate]) && is_scalar($metadata[$candidate])) {
                $provenance['source_ref'] = (string) $metadata[$candidate];
                break;
            }
        }

        $explicit = $metadata['rag_vector_metadata'] ?? [];
        if (is_array($explicit)) {
            /** @var array<string, mixed> $explicit */
            $provenance = [...$provenance, ...$explicit];
        }

        return $provenance;
    }

    /**
     * Chunk metadata safe to store in the vector payload (drop volatile keys).
     *
     * @return array<string, mixed>
     */
    private function payloadMetadata(TextChunk $chunk): array
    {
        $excluded = ['is_parent', 'parent_index', 'parent_content', 'pii_tokens', 'pii_redactions'];

        return array_diff_key($chunk->metadata, array_flip($excluded));
    }
}
