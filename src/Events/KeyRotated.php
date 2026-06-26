<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant KEK has been rotated (FR-SEC-05).
 */
final class KeyRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $keyId,
        public readonly string $tenantId,
    ) {}
}
