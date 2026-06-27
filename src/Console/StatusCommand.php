<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Models\Document;

/**
 * Pipeline status by document state (FR-DX-05, NFR-OB-04).
 */
final class StatusCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:status {--tenant= : Limit to a tenant}';

    protected $description = 'Show document counts grouped by pipeline status';

    public function handle(): int
    {
        $query = Document::query();

        if (($tenant = $this->stringOption('tenant')) !== null) {
            $query->where('tenant_id', $tenant);
        }

        $counts = $query->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $this->table(['Status', 'Documents'], $counts->map(fn ($total, $status) => [$status, $total])->values()->all());

        return self::SUCCESS;
    }
}
