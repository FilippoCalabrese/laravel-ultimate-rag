<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A retrieval query has been executed.
 */
final class SearchPerformed
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $query,
        public readonly int $resultCount,
    ) {}
}
