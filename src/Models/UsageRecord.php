<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant consumption/cost record (model-data §8, FR-MT-05, NFR-CT-02).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $operation
 * @property int $tokens
 * @property float $cost
 * @property string $currency
 * @property string $period
 * @property array<string, mixed>|null $metadata
 */
class UsageRecord extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'tokens' => 'integer',
        'cost' => 'float',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.usage_records', 'rag_usage_records');
    }
}
