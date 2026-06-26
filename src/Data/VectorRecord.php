<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use InvalidArgumentException;

/**
 * A point to upsert into a vector store: stable id, vector and metadata payload.
 *
 * The id makes upserts idempotent (FR-VS-12). Payload holds the filterable
 * metadata (tenant_id, namespace, document_id, tags...) used by FR-VS-08.
 */
final class VectorRecord
{
    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $vector,
        public readonly array $metadata = [],
    ) {
        if ($vector === []) {
            throw new InvalidArgumentException('A vector record cannot have an empty vector.');
        }
    }

    public function dimensions(): int
    {
        return count($this->vector);
    }

    public function tenantId(): ?string
    {
        $value = $this->metadata['tenant_id'] ?? null;

        return $value === null ? null : (string) $value;
    }
}
