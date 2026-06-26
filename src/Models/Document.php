<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Sellinnate\RagEngine\Database\Factories\DocumentFactory;

/**
 * A source ingested into the engine (model-data §8).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string $content_hash
 * @property string|null $mime
 * @property int|null $size
 * @property array<string, mixed>|null $metadata
 * @property int $version
 * @property string $status
 * @property string|null $encrypted_content_ref
 * @property string|null $dek_id
 * @property string|null $language
 * @property Carbon|null $soft_deleted_at
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'version' => 'integer',
        'size' => 'integer',
        'soft_deleted_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.documents', 'rag_documents');
    }

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_id');
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }
}
