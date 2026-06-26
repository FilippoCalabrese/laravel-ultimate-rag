<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Audit;

use Sellinnate\RagEngine\Models\AuditAnchor;
use Sellinnate\RagEngine\Models\AuditEntry;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Append-only, hash-chained audit log (NFR-CO-03). Each entry's hash covers the
 * previous entry's hash AND a monotonic per-tenant sequence number; a per-tenant
 * {@see AuditAnchor} records the chain head (seq + hash). Combined with the
 * DB-level WORM triggers this makes tampering — including trailing-entry
 * truncation and wholesale deletion — detectable via {@see verify()}.
 *
 * For maximum assurance the anchor should also be replicated to an external,
 * independently-protected store; in-DB it raises the bar against casual tampering.
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $action, ?string $target = null, array $context = [], ?string $actor = null): AuditEntry
    {
        $tenantId = $this->tenant->id();

        $anchor = AuditAnchor::query()->whereKey($tenantId)->first();
        $seq = $anchor !== null ? $anchor->seq + 1 : 1;
        $hashPrev = $anchor?->head_hash;

        $entry = new AuditEntry([
            'tenant_id' => $tenantId,
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'context' => $context,
            'seq' => $seq,
            'hash_prev' => $hashPrev,
        ]);
        $entry->created_at = now();
        $entry->hash = $this->hash($entry, $hashPrev);
        $entry->save();

        AuditAnchor::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            ['seq' => $seq, 'head_hash' => $entry->hash, 'updated_at' => now()],
        );

        return $entry;
    }

    /**
     * Recompute the chain for a tenant and confirm no entry was tampered,
     * truncated or deleted.
     */
    public function verify(string $tenantId): bool
    {
        $anchor = AuditAnchor::query()->whereKey($tenantId)->first();
        $entries = AuditEntry::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('seq')
            ->orderBy('id')
            ->get();

        // No entries and no anchor is a valid empty chain; anchor without entries
        // (or vice-versa) signals truncation.
        if ($anchor === null) {
            return $entries->isEmpty();
        }

        if ($entries->count() !== $anchor->seq) {
            return false; // truncation / deletion
        }

        $expectedPrev = null;
        $expectedSeq = 1;

        foreach ($entries as $entry) {
            if ($entry->seq !== $expectedSeq || $entry->hash_prev !== $expectedPrev) {
                return false;
            }

            if ($entry->hash !== $this->hash($entry, $expectedPrev)) {
                return false;
            }

            $expectedPrev = $entry->hash;
            $expectedSeq++;
        }

        return $expectedPrev === $anchor->head_hash;
    }

    private function hash(AuditEntry $entry, ?string $hashPrev): string
    {
        $payload = implode('|', [
            $hashPrev ?? '',
            (string) $entry->seq,
            $entry->tenant_id,
            $entry->actor ?? '',
            $entry->action,
            $entry->target ?? '',
            json_encode($entry->context ?? [], JSON_THROW_ON_ERROR),
            $entry->created_at->toIso8601String(),
        ]);

        return hash('sha256', $payload);
    }
}
