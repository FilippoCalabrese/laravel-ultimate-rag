<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ingestion;

/**
 * Normalized description of something to ingest, whatever its origin
 * (FR-IN-01..05). Carries the raw content plus provenance hints (FR-IN-07) and
 * arbitrary caller metadata (FR-IN-09).
 */
final class IngestionSource
{
    public const TYPE_TEXT = 'text';

    public const TYPE_UPLOAD = 'upload';

    public const TYPE_URL = 'url';

    public const TYPE_ELOQUENT = 'eloquent';

    public const TYPE_STORAGE = 'storage';

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $sourceType,
        public readonly array $metadata = [],
    ) {}

    /**
     * Stable content hash for deduplication and idempotency (FR-IN-06).
     */
    public function contentHash(): string
    {
        return hash('sha256', $this->content);
    }

    public function size(): int
    {
        return strlen($this->content);
    }

    /**
     * Optional logical key used to group versions of the same document
     * (FR-IN-08). Falls back to filename/url when not given explicitly.
     */
    public function documentKey(): ?string
    {
        foreach (['document_key', 'filename', 'url', 'key'] as $candidate) {
            if (isset($this->metadata[$candidate]) && is_string($this->metadata[$candidate])) {
                return $this->metadata[$candidate];
            }
        }

        return null;
    }
}
