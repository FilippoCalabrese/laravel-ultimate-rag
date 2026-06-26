<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Immutable, append-only, hash-chained audit entry (model-data §8, NFR-CO-03).
 *
 * Updates and deletes are blocked at the model layer so the chain stays
 * tamper-evident; physical purge only happens through crypto-shredding.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $actor
 * @property string $action
 * @property string|null $target
 * @property array<string, mixed>|null $context
 * @property int $seq
 * @property string|null $hash_prev
 * @property string $hash
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'seq' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.audit_entries', 'rag_audit_entries');
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit entries are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit entries are immutable and cannot be deleted.');
        });
    }
}
