<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Embedding metadata + provenance for a chunk (model-data §8).
 *
 * @property string $id
 * @property string $chunk_id
 * @property string $tenant_id
 * @property string $model
 * @property int $dimensions
 * @property string $provider
 * @property string|null $vector_ref
 * @property float $cost
 */
class EmbeddingRecord extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'dimensions' => 'integer',
        'cost' => 'float',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.embeddings', 'rag_embeddings');
    }
}
