<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A source has been ingested and persisted as a Document.
 */
final class DocumentIngested
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
    ) {}
}
