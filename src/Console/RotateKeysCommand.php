<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Security\KeyRotationService;

/**
 * Rotate a tenant's KEK and re-wrap its DEKs (FR-DX-05, FR-SEC-05).
 */
final class RotateKeysCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:rotate-keys {tenant : The tenant id}';

    protected $description = 'Rotate a tenant KEK and re-wrap its data keys';

    public function handle(KeyRotationService $rotation): int
    {
        $tenant = $this->stringArgument('tenant');
        $count = $rotation->rotate($tenant);

        $this->info("Rotated key for [{$tenant}] and re-wrapped {$count} payload(s).");

        return self::SUCCESS;
    }
}
