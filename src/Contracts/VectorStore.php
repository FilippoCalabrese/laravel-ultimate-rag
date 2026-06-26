<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Data\VectorRecord;

/**
 * Vector index abstraction (FR-VS). Drivers: Qdrant (primary), pgvector,
 * in-memory (deterministic, for tests). All share this contract so consumers
 * never depend on a concrete backend.
 */
interface VectorStore
{
    /**
     * Ensure a namespace/collection exists with the given vector dimensions and
     * distance metric (FR-VS-09, FR-VS-11). Idempotent.
     */
    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void;

    public function namespaceExists(string $namespace): bool;

    public function deleteNamespace(string $namespace): void;

    /**
     * Idempotent upsert of records into a namespace (FR-VS-12).
     *
     * @param  list<VectorRecord>  $records
     */
    public function upsert(string $namespace, array $records): void;

    /**
     * Vector similarity search with optional metadata pre/post-filtering and
     * score threshold (FR-RT-01, FR-RT-02, FR-VS-08).
     *
     * @param  list<float>  $vector
     * @return list<SearchHit>
     */
    public function search(string $namespace, array $vector, RetrievalQuery $query): array;

    /**
     * Idempotent deletion of records by id (FR-VS-12).
     *
     * @param  list<string>  $ids
     */
    public function delete(string $namespace, array $ids): void;

    /**
     * Delete every record matching a metadata filter (used for tenant/document
     * purge and atomic version replacement, FR-IN-08).
     *
     * @param  array<string, mixed>  $filter
     */
    public function deleteByFilter(string $namespace, array $filter): void;

    public function count(string $namespace): int;

    public function name(): string;
}
