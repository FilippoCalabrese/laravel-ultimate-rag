<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document has been split into chunks.
 */
final class DocumentChunked
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly int $chunkCount,
    ) {}
}
