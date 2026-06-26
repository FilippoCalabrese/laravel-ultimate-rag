<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Chunks of a document have been embedded into vectors.
 */
final class ChunksEmbedded
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly int $chunkCount,
    ) {}
}
