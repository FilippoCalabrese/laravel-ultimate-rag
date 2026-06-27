<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Evaluation\EvaluationCase;
use Sellinnate\RagEngine\Evaluation\Evaluator;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Retrieval-quality evaluation (FR-EV). Runs a labelled dataset through the
 * retrieval pipeline and reports hit-rate, recall@k, precision@k and MRR.
 *
 * The dataset is a JSON array of cases:
 *   [{ "query": "how do refunds work?", "relevant": ["doc-id-1", "chunk-id-2"] }]
 */
final class EvaluateCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:evaluate {dataset : Path to a JSON file of {query, relevant[]} cases}
        {--k=5 : Top-k to evaluate}
        {--tenant= : Evaluate within this tenant}
        {--hybrid : Use hybrid search}
        {--rerank= : Reranker connection to use}';

    protected $description = 'Evaluate retrieval quality against a labelled dataset';

    public function handle(Evaluator $evaluator, TenantContext $tenant): int
    {
        $path = $this->stringArgument('dataset');

        if (! is_file($path)) {
            $this->error("Dataset not found: {$path}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || $decoded === []) {
            $this->error('Dataset must be a non-empty JSON array of {query, relevant[]} cases.');

            return self::FAILURE;
        }

        $cases = array_map(
            static fn (array $row): EvaluationCase => EvaluationCase::fromArray($row),
            array_values(array_filter($decoded, 'is_array')),
        );

        $k = max(1, (int) $this->option('k'));
        $options = [
            'hybrid' => (bool) $this->option('hybrid'),
            'rerank' => $this->stringOption('rerank') !== null,
            'reranker' => $this->stringOption('rerank'),
        ];

        $run = fn () => $evaluator->evaluateRetrieval($cases, $k, $options);
        $report = ($t = $this->stringOption('tenant')) !== null ? $tenant->runAs($t, $run) : $run();

        $this->table(['Metric', 'Value'], [
            ['Cases', (string) $report->count],
            ['k', (string) $report->k],
            ['Hit rate', $this->pct($report->hitRate)],
            ['Recall@k', $this->pct($report->recallAtK)],
            ['Precision@k', $this->pct($report->precisionAtK)],
            ['MRR', number_format($report->mrr, 4)],
        ]);

        return self::SUCCESS;
    }

    private function pct(float $value): string
    {
        return number_format($value * 100, 1).'%';
    }
}
