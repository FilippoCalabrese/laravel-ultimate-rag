<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch ingestion state (model-data §8, FR-OR-02).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property int $total
 * @property int $processed
 * @property int $failed
 * @property array<int, mixed>|null $errors
 */
class IngestionRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'total' => 'integer',
        'processed' => 'integer',
        'failed' => 'integer',
        'errors' => 'array',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.ingestion_runs', 'rag_ingestion_runs');
    }
}
