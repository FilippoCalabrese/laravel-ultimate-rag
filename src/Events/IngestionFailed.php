<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ingestion of a document failed.
 */
final class IngestionFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $reason,
    ) {}
}
