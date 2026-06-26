<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Models\DataKey;
use Sellinnate\RagEngine\Models\EmbeddingRecord;
use Sellinnate\RagEngine\Models\IngestionRun;
use Sellinnate\RagEngine\Models\UsageRecord;

it('persists a data key reference', function () {
    $key = DataKey::create([
        'tenant_id' => 't1',
        'wrapped_dek' => 'wrapped',
        'kek_ref' => 'kek-1',
    ]);

    expect($key->getTable())->toBe('rag_data_keys')
        ->and($key->fresh()->kek_ref)->toBe('kek-1');
});

it('persists an embedding record with casts', function () {
    $record = EmbeddingRecord::create([
        'chunk_id' => 'c1',
        'tenant_id' => 't1',
        'model' => 'fake-embed-v1',
        'dimensions' => 8,
        'provider' => 'fake',
        'cost' => 0.0,
    ]);

    expect($record->getTable())->toBe('rag_embeddings')
        ->and($record->fresh()->dimensions)->toBe(8);
});

it('persists an ingestion run with counters', function () {
    $run = IngestionRun::create([
        'tenant_id' => 't1',
        'status' => 'pending',
        'total' => 10,
        'errors' => ['x'],
    ]);

    expect($run->getTable())->toBe('rag_ingestion_runs')
        ->and($run->fresh()->total)->toBe(10)
        ->and($run->fresh()->errors)->toBe(['x']);
});

it('persists a usage record', function () {
    $usage = UsageRecord::create([
        'tenant_id' => 't1',
        'operation' => 'embedding',
        'tokens' => 100,
        'cost' => 0.5,
        'period' => '2026-06',
    ]);

    expect($usage->getTable())->toBe('rag_usage_records')
        ->and($usage->fresh()->tokens)->toBe(100)
        ->and($usage->fresh()->cost)->toBe(0.5);
});
