<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant audit-chain anchor (high-water mark) — records the latest sequence
 * number and head hash so that truncation or wholesale deletion of audit
 * entries is detectable (NFR-CO-03).
 *
 * @property string $tenant_id
 * @property int $seq
 * @property string|null $head_hash
 */
class AuditAnchor extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'seq' => 'integer',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.audit_anchors', 'rag_audit_anchors');
    }
}
