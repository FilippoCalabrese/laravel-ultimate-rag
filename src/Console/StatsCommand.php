<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Observability\UsageRecorder;
use Sellinnate\RagEngine\Tenancy\TenantQuota;

/**
 * Per-tenant consumption + quota usage (FR-DX-05, FR-MT-05, NFR-CT-02).
 */
final class StatsCommand extends Command
{
    protected $signature = 'rag:stats {tenant : The tenant id} {--period= : YYYY-MM period}';

    protected $description = 'Show token/cost usage and quota consumption for a tenant';

    public function handle(UsageRecorder $usage, TenantQuota $quota): int
    {
        $tenant = (string) $this->argument('tenant');
        $period = $this->option('period');

        $snapshot = $quota->usageSnapshot($tenant);

        $this->table(['Metric', 'Value'], [
            ['Documents', $snapshot['documents']],
            ['Corpus bytes', $snapshot['corpus_bytes']],
            ['Embedding tokens', $usage->totalTokens($tenant, $period)],
            ['Total cost', number_format($usage->totalCost($tenant, $period), 6)],
        ]);

        return self::SUCCESS;
    }
}
