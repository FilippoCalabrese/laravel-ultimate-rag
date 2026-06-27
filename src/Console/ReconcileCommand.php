<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Recovery\Reconciler;

/**
 * Vectors ↔ metadata reconciliation report (FR-DX-05, NFR-DR-02).
 */
final class ReconcileCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:reconcile {tenant : The tenant id}';

    protected $description = 'Report chunks missing vectors and orphan embeddings for a tenant';

    public function handle(Reconciler $reconciler): int
    {
        $tenant = $this->stringArgument('tenant');
        $report = $reconciler->reconcile($tenant);

        $this->table(['Issue', 'Count'], [
            ['Chunks missing embeddings', count($report['missing_embeddings'])],
            ['Orphan embeddings', count($report['orphan_embeddings'])],
        ]);

        if ($reconciler->isConsistent($tenant)) {
            $this->info('Corpus is consistent.');

            return self::SUCCESS;
        }

        $this->warn('Inconsistencies detected — re-index affected documents.');

        return self::FAILURE;
    }
}
