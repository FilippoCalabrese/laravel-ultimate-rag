<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\DataKey;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Models\IngestionRun;
use Sellinnate\RagEngine\Models\ShreddedTenant;
use Sellinnate\RagEngine\Models\UsageRecord;

/**
 * Crypto-shredding service (FR-SEC-04, NFR-CO-01): GDPR erasure by destroying
 * keys (making encrypted DB content irrecoverable, even from DB backups) AND
 * deleting the plaintext vectors from the live store across every namespace the
 * tenant used. Pre-existing vector-store backups are outside the key-destruction
 * guarantee and are the operator's retention responsibility.
 *
 * Records shredded tenants in a registry so they cannot be silently
 * re-provisioned (resolves the Cycle-0 tombstone gap).
 */
final class CryptoShredder
{
    public function __construct(
        private readonly KeyManagement $kms,
        private readonly Ingestor $ingestor,
        private readonly AuditLogger $audit,
        private readonly VectorStoreManager $stores,
        private readonly Config $config,
    ) {}

    /**
     * Crypto-shred an entire tenant: destroy its KEK, drop wrapped DEKs, all
     * derived rows AND the plaintext vectors in the store, and tombstone it.
     */
    public function shredTenant(string $tenantId, ?string $reason = null): void
    {
        // Vectors hold plaintext content in their payload — erase them from EVERY
        // namespace the tenant was indexed into, plus the configured default (C2).
        $namespaces = Document::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('indexed_namespace')
            ->distinct()
            ->pluck('indexed_namespace')
            ->all();
        $namespaces[] = (string) $this->config->get('rag-engine.namespace', 'documents');

        $store = $this->stores->driver();
        foreach (array_unique($namespaces) as $namespace) {
            if ($store->namespaceExists((string) $namespace)) {
                $store->deleteByFilter((string) $namespace, ['tenant_id' => $tenantId]);
            }
        }

        DB::transaction(function () use ($tenantId): void {
            Chunk::where('tenant_id', $tenantId)->delete();
            Document::where('tenant_id', $tenantId)->delete();
            DataKey::where('tenant_id', $tenantId)->delete();
            UsageRecord::where('tenant_id', $tenantId)->delete();
            EmbeddingRecord::where('tenant_id', $tenantId)->delete();
            IngestionRun::where('tenant_id', $tenantId)->delete();
        });

        // Destroying the KEK makes every value wrapped by it unrecoverable.
        $this->kms->destroyKey($tenantId);

        // Evict the tenant's cached embeddings (recoverable via embedding
        // inversion) when the cache store supports tagging; otherwise they expire.
        try {
            Cache::tags(
                CachingEmbedder::tenantTag($tenantId)
            )->flush();
        } catch (\Throwable) {
            // Non-taggable cache store: entries expire by TTL.
        }

        ShreddedTenant::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            ['reason' => $reason, 'shredded_at' => now()],
        );

        $this->audit->log('tenant.shredded', $tenantId, ['reason' => $reason]);
        event(new DataShredded($tenantId, $tenantId, 'tenant'));
    }

    /**
     * Crypto-shred a single document (delete the only copy of its wrapped DEK).
     */
    public function shredDocument(Document $document): void
    {
        $id = (string) $document->id;
        $tenantId = $document->tenant_id;

        $this->ingestor->purge($document);
        $this->audit->log('document.shredded', $id);
        event(new DataShredded($id, $tenantId, 'document'));
    }

    public function isShredded(string $tenantId): bool
    {
        return ShreddedTenant::query()->whereKey($tenantId)->exists();
    }

    /**
     * Guard used at ingestion time to refuse re-provisioning a shredded tenant.
     */
    public function assertNotShredded(string $tenantId): void
    {
        if ($this->isShredded($tenantId)) {
            throw new RagException(
                "Tenant [{$tenantId}] has been crypto-shredded and cannot be re-provisioned."
            );
        }
    }
}
