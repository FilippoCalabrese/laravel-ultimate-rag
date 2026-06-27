<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Evaluation\EvaluationCase;
use Sellinnate\RagEngine\Evaluation\Evaluator;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Ingestion\IngestionSource;

beforeEach(function () {
    $a = Rag::ingest(new IngestionSource('Photosynthesis converts light into chemical energy in plants.', 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::process($a);
    $b = Rag::ingest(new IngestionSource('The mitochondria is the powerhouse of the cell.', 'text/plain', IngestionSource::TYPE_TEXT));
    Rag::process($b);

    $this->docA = $a->id;
    $this->docB = $b->id;
});

it('computes retrieval-quality metrics over labelled cases (FR-EV)', function () {
    $cases = [
        // k=5 with only 2 docs → both are always retrieved, so this hits deterministically.
        new EvaluationCase('Photosynthesis converts light into chemical energy in plants.', [$this->docA]),
        // No retrievable doc matches this label → a miss.
        new EvaluationCase('unrelated query', ['no-such-id']),
    ];

    $report = app(Evaluator::class)->evaluateRetrieval($cases, k: 5);

    expect($report->count)->toBe(2)
        ->and($report->hitRate)->toBe(0.5)
        ->and($report->recallAtK)->toBe(0.5)
        ->and($report->precisionAtK)->toBe(0.1)   // (1/5 + 0) / 2
        ->and($report->mrr)->toBeGreaterThan(0.0)
        ->and($report->cases[0]['hit'])->toBeTrue()
        ->and($report->cases[1]['hit'])->toBeFalse();
});

it('reports a perfect score when every case retrieves its relevant doc', function () {
    $cases = [
        new EvaluationCase('Photosynthesis converts light into chemical energy in plants.', [$this->docA]),
        new EvaluationCase('The mitochondria is the powerhouse of the cell.', [$this->docB]),
    ];

    $report = app(Evaluator::class)->evaluateRetrieval($cases, k: 5);

    expect($report->hitRate)->toBe(1.0)->and($report->recallAtK)->toBe(1.0);
});

it('rag:evaluate runs a dataset file and reports SUCCESS', function () {
    $file = tempnam(sys_get_temp_dir(), 'eval').'.json';
    file_put_contents($file, json_encode([
        ['query' => 'Photosynthesis converts light into chemical energy in plants.', 'relevant' => [$this->docA]],
    ]));

    $this->artisan('rag:evaluate', ['dataset' => $file, '--k' => 5])
        ->assertExitCode(0);

    unlink($file);
});
