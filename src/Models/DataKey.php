<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reference to a wrapped DEK (model-data §8). The plaintext DEK is never stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $wrapped_dek
 * @property string $kek_ref
 * @property Carbon|null $rotated_at
 */
class DataKey extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'rotated_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.data_keys', 'rag_data_keys');
    }
}
