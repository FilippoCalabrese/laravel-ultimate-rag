<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An indexable fragment of a document (model-data §8).
 *
 * @property string $id
 * @property string $document_id
 * @property string $tenant_id
 * @property string|null $encrypted_content
 * @property string|null $content
 * @property int $position
 * @property int $offset
 * @property array<string, mixed>|null $metadata
 * @property string|null $parent_chunk_id
 * @property int|null $token_count
 */
class Chunk extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'position' => 'integer',
        'offset' => 'integer',
        'token_count' => 'integer',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.chunks', 'rag_chunks');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
