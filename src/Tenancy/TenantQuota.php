<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Tenancy;

use Illuminate\Contracts\Config\Repository as Config;
use Sellinnate\RagEngine\Exceptions\QuotaExceededException;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Observability\UsageRecorder;

/**
 * Per-tenant quota enforcement (FR-MT-04, NFR-CT-04): document count, corpus
 * size and embedding-token budget. Quotas come from config (global defaults +
 * optional per-tenant override); null means unlimited.
 */
final class TenantQuota
{
    public function __construct(
        private readonly Config $config,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * Throw if ingesting a source of $sizeBytes would breach a quota.
     */
    public function assertCanIngest(string $tenantId, int $sizeBytes): void
    {
        $maxDocuments = $this->quota($tenantId, 'max_documents');
        if ($maxDocuments !== null && $this->documentCount($tenantId) >= $maxDocuments) {
            throw new QuotaExceededException("Tenant [{$tenantId}] reached its document quota ({$maxDocuments}).");
        }

        $maxBytes = $this->quota($tenantId, 'max_corpus_bytes');
        if ($maxBytes !== null && $this->corpusBytes($tenantId) + $sizeBytes > $maxBytes) {
            throw new QuotaExceededException("Tenant [{$tenantId}] reached its corpus size quota ({$maxBytes} bytes).");
        }

        $maxTokens = $this->quota($tenantId, 'max_embedding_tokens');
        if ($maxTokens !== null && $this->usage->totalTokens($tenantId) >= $maxTokens) {
            throw new QuotaExceededException("Tenant [{$tenantId}] reached its embedding-token quota ({$maxTokens}).");
        }
    }

    /**
     * @return array{documents: int, corpus_bytes: int, embedding_tokens: int}
     */
    public function usageSnapshot(string $tenantId): array
    {
        return [
            'documents' => $this->documentCount($tenantId),
            'corpus_bytes' => $this->corpusBytes($tenantId),
            'embedding_tokens' => $this->usage->totalTokens($tenantId),
        ];
    }

    private function quota(string $tenantId, string $key): ?int
    {
        $override = $this->config->get("rag-engine.tenancy.tenant_quotas.{$tenantId}.{$key}");
        $value = $override ?? $this->config->get("rag-engine.tenancy.quotas.{$key}");

        return $value === null ? null : (int) $value;
    }

    private function documentCount(string $tenantId): int
    {
        return Document::query()->where('tenant_id', $tenantId)->whereNull('soft_deleted_at')->count();
    }

    private function corpusBytes(string $tenantId): int
    {
        return (int) Document::query()->where('tenant_id', $tenantId)->whereNull('soft_deleted_at')->sum('size');
    }
}
