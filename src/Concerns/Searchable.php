<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Concerns;

use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Document;

/**
 * Scout-style trait (FR-DX-04): makes an Eloquent model ingestable into the RAG
 * engine. Models define their searchable text via {@see toRagContent()}.
 *
 * Auto-syncs on save/delete when `rag-engine.searchable_auto_sync` is enabled.
 */
trait Searchable
{
    public static function bootSearchable(): void
    {
        if (! config('rag-engine.searchable_auto_sync', false)) {
            return;
        }

        static::saved(fn ($model) => $model->makeSearchable());
        static::deleted(fn ($model) => $model->removeFromSearch());
    }

    /**
     * The text to index. Uses an explicit allowlist — never every attribute —
     * to avoid leaking secrets (passwords, tokens) into the index/LLM. Define
     * `$ragSearchable` (attribute keys) or rely on the model's `$fillable`;
     * override this method for full control.
     */
    public function toRagContent(): string
    {
        /** @var list<string> $keys */
        $keys = property_exists($this, 'ragSearchable') && is_array($this->ragSearchable)
            ? $this->ragSearchable
            : $this->getFillable();

        $parts = [];
        foreach ($keys as $key) {
            $value = $this->getAttribute($key);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Metadata propagated to the indexed document/chunks.
     *
     * @return array<string, mixed>
     */
    public function ragMetadata(): array
    {
        return [
            'model' => static::class,
            'model_id' => $this->getKey(),
            'document_key' => static::class.':'.$this->getKey(),
        ];
    }

    /**
     * Ingest + index this model now (FR-DX-04).
     */
    public function makeSearchable(): int
    {
        $source = new IngestionSource(
            $this->toRagContent(),
            'text/plain',
            IngestionSource::TYPE_ELOQUENT,
            $this->ragMetadata(),
        );

        $document = Rag::ingest($source);

        return Rag::process($document);
    }

    /**
     * Remove this model's documents from the index (soft-delete + purge).
     */
    public function removeFromSearch(): void
    {
        $key = static::class.':'.$this->getKey();

        Document::query()
            ->where('metadata->document_key', $key)
            ->each(fn ($document) => Rag::ingestor()->purge($document));
    }
}
