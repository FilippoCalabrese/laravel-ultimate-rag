<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Security;

use Illuminate\Support\Facades\DB;
use Sellinnate\RagEngine\Audit\AuditLogger;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Data\EncryptedPayload;
use Sellinnate\RagEngine\Events\KeyRotated;
use Sellinnate\RagEngine\Exceptions\EncryptionException;
use Sellinnate\RagEngine\Models\Chunk;
use Sellinnate\RagEngine\Models\Document;

/**
 * Key rotation (FR-SEC-05): rotates a tenant's KEK and re-wraps every per-document
 * and per-chunk DEK under the new KEK version — without re-ingesting or
 * re-embedding content. Rotation is non-destructive: old DEKs keep unwrapping
 * until re-wrapped.
 */
final class KeyRotationService
{
    public function __construct(
        private readonly KeyManagement $kms,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int Number of payloads re-wrapped.
     */
    public function rotate(string $tenantId): int
    {
        // Add a new KEK version; existing wrapped DEKs still unwrap via old versions.
        $this->kms->rotateKey($tenantId);

        $rewrapped = 0;
        $failures = [];

        try {
            // Transactional re-wrap so a fatal error rolls back partial DB updates.
            DB::transaction(function () use ($tenantId, &$rewrapped, &$failures): void {
                Document::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull('encrypted_content_ref')
                    ->where('dek_id', $tenantId)
                    ->chunkById(100, function ($documents) use ($tenantId, &$rewrapped, &$failures): void {
                        foreach ($documents as $document) {
                            $this->rewrapModel($document, 'encrypted_content_ref', $tenantId, $rewrapped, $failures);
                        }
                    });

                Chunk::query()
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull('encrypted_content')
                    ->chunkById(200, function ($chunks) use ($tenantId, &$rewrapped, &$failures): void {
                        foreach ($chunks as $chunk) {
                            $this->rewrapModel($chunk, 'encrypted_content', $tenantId, $rewrapped, $failures);
                        }
                    });
            });
        } finally {
            // Always record the rotation, including a partial/failed run.
            $this->audit->log('key.rotated', $tenantId, ['rewrapped' => $rewrapped, 'failures' => $failures]);
            event(new KeyRotated($tenantId, $tenantId));
        }

        return $rewrapped;
    }

    /**
     * Re-wrap one model, collecting (not throwing on) a corrupt DEK so one bad
     * row never aborts the whole rotation.
     *
     * @param  list<string>  $failures
     */
    private function rewrapModel(Document|Chunk $model, string $column, string $tenantId, int &$rewrapped, array &$failures): void
    {
        $raw = $model->getAttribute($column);

        if (! is_string($raw) || $raw === '') {
            return;
        }

        try {
            /** @var array<string, string> $decoded */
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $payload = EncryptedPayload::fromArray($decoded);

            $dek = $this->kms->unwrapDataKey($tenantId, $payload->wrappedDek);
            $newWrapped = $this->kms->wrapDataKey($tenantId, $dek);

            $model->setAttribute($column, json_encode([
                'ciphertext' => $payload->ciphertext,
                'wrapped_dek' => $newWrapped,
                'key_id' => $payload->keyId,
            ], JSON_THROW_ON_ERROR));
            $model->save();
            $rewrapped++;
        } catch (EncryptionException|\JsonException $e) {
            $failures[] = (string) $model->getKey();
        }
    }
}
