<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Observability;

use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Models\UsageRecord;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Records and aggregates token/cost usage per tenant (FR-EM-07, FR-MT-05,
 * NFR-CT-01/02). Every embedding/LLM/rerank operation reports here.
 */
final class UsageRecorder
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $operation, Usage $usage, array $metadata = [], ?string $period = null): UsageRecord
    {
        return UsageRecord::create([
            'tenant_id' => $this->tenant->id(),
            'operation' => $operation,
            'tokens' => $usage->tokens,
            'cost' => $usage->cost,
            'currency' => $usage->currency,
            'period' => $period ?? $this->currentPeriod(),
            'metadata' => $metadata,
        ]);
    }

    public function totalCost(string $tenantId, ?string $period = null): float
    {
        return (float) UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->when($period !== null, fn ($q) => $q->where('period', $period))
            ->sum('cost');
    }

    public function totalTokens(string $tenantId, ?string $period = null): int
    {
        return (int) UsageRecord::query()
            ->where('tenant_id', $tenantId)
            ->when($period !== null, fn ($q) => $q->where('period', $period))
            ->sum('tokens');
    }

    private function currentPeriod(): string
    {
        return now()->format('Y-m');
    }
}
