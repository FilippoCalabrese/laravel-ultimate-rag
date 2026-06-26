<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Models\UsageRecord;
use Sellinnate\RagEngine\Observability\UsageRecorder;

it('embeds via the default provider and tracks no cost for the fake embedder', function () {
    $response = app(EmbeddingService::class)->embed(['a', 'b', 'c']);

    expect($response)->toHaveCount(3)
        ->and($response->model)->toBe('fake-embed-v1');
});

it('records usage per tenant when there is cost (FR-EM-07, FR-MT-05)', function () {
    config()->set('rag-engine.embedders.mistral.cost_per_1k', 0.2);
    config()->set('rag-engine.defaults.embedder', 'mistral');
    Http::fake(['*/embeddings' => Http::response([
        'data' => [['embedding' => array_fill(0, 1024, 0.01)]],
        'usage' => ['total_tokens' => 500],
    ])]);

    app(EmbeddingService::class)->embed(['hello']);

    $record = UsageRecord::where('operation', 'embedding')->first();
    expect($record)->not->toBeNull()
        ->and($record->tokens)->toBe(500)
        ->and($record->cost)->toBe(0.1)
        ->and($record->tenant_id)->toBe('default');
});

it('aggregates cost and tokens per tenant (NFR-CT-02)', function () {
    $recorder = app(UsageRecorder::class);
    $recorder->record('embedding', new Usage(100, 1.0));
    $recorder->record('embedding', new Usage(50, 0.5));

    expect($recorder->totalTokens('default'))->toBe(150)
        ->and($recorder->totalCost('default'))->toBe(1.5);
});

it('resolves real providers wrapped in a caching decorator (FR-EM-05)', function () {
    config()->set('rag-engine.embedders.mistral.api_key', 'k');

    expect(app(EmbedderManager::class)->driver('mistral'))->toBeInstanceOf(CachingEmbedder::class);
});

it('batches large inputs (FR-EM-04)', function () {
    $texts = array_map(fn ($i) => "text-{$i}", range(1, 250));

    $response = app(EmbeddingService::class)->embed($texts, batchSize: 96);

    expect($response)->toHaveCount(250);
});
