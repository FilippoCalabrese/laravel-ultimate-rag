<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Events\DataShredded;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\DataKey;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Models\ShreddedTenant;
use Sellinnate\RagEngine\Models\UsageRecord;

/**
 * Crypto-shredding service (FR-SEC-04, NFR-CO-01): GDPR erasure by destroying
 * keys, making derived data irrecoverable — even from backups.
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
        // Vectors hold plaintext content in their payload — erase them too (C2).
        $namespace = (string) $this->config->get('rag-engine.namespace', 'documents');
        $store = $this->stores->driver();
        if ($store->namespaceExists($namespace)) {
            $store->deleteByFilter($namespace, ['tenant_id' => $tenantId]);
        }

        DB::transaction(function () use ($tenantId): void {
            Chunk::where('tenant_id', $tenantId)->delete();
            Document::where('tenant_id', $tenantId)->delete();
            DataKey::where('tenant_id', $tenantId)->delete();
            UsageRecord::where('tenant_id', $tenantId)->delete();
        });

        // Destroying the KEK makes every value wrapped by it unrecoverable.
        $this->kms->destroyKey($tenantId);

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
