<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registry of crypto-shredded tenants (FR-SEC-04). Prevents silent
 * re-provisioning of a tenant whose key was destroyed for GDPR erasure.
 *
 * @property string $tenant_id
 * @property string|null $reason
 * @property Carbon $shredded_at
 */
class ShreddedTenant extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'shredded_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('rag-engine.tables.shredded_tenants', 'rag_shredded_tenants');
    }
}
