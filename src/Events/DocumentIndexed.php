<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document's vectors have been written to the vector store.
 */
final class DocumentIndexed
{
    use Dispatchable;

    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $namespace,
    ) {}
}
