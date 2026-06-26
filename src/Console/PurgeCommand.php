<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Security\CryptoShredder;

/**
 * Crypto-shred a tenant (FR-DX-05, FR-SEC-04, NFR-CO-01).
 */
final class PurgeCommand extends Command
{
    protected $signature = 'rag:purge {tenant : The tenant id} {--reason=} {--force : Skip confirmation}';

    protected $description = 'Crypto-shred a tenant: destroy its key and purge all derived data';

    public function handle(CryptoShredder $shredder): int
    {
        $tenant = (string) $this->argument('tenant');

        if (! $this->option('force') && ! $this->confirm("Permanently crypto-shred tenant [{$tenant}]? This is irreversible.")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $shredder->shredTenant($tenant, $this->option('reason'));
        $this->info("Tenant [{$tenant}] has been crypto-shredded.");

        return self::SUCCESS;
    }
}
