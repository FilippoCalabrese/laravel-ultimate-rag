<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A key was destroyed, crypto-shredding its derived data (FR-SEC-04).
 */
final class DataShredded
{
    use Dispatchable;

    public function __construct(
        public readonly string $keyId,
        public readonly string $tenantId,
        public readonly string $scope,
    ) {}
}
