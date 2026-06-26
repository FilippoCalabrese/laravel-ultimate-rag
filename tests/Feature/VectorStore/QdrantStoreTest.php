<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\VectorStore\QdrantStore;

function qdrant(): QdrantStore
{
    return new QdrantStore(app(HttpFactory::class), 'http://localhost:6333', 'secret');
}

it('creates a collection with size and distance, sending the api key (FR-VS-01)', function () {
    Http::fake([
        '*/collections/docs' => Http::sequence()
            ->push('', 404)   // namespaceExists check
            ->push(['result' => true], 200), // PUT create
    ]);

    qdrant()->createNamespace('docs', 8, 'cosine');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/collections/docs')
        && $request['vectors']['size'] === 8
        && $request['vectors']['distance'] === 'Cosine'
        && $request->hasHeader('api-key', 'secret'));
});

it('rejects an unsupported metric', function () {
    $store = qdrant();
    $store->createNamespace('docs', 8, 'manhattan');
})->throws(RagException::class, 'metric');

it('upserts points', function () {
    Http::fake(['*/points*' => Http::response(['result' => ['status' => 'completed']])]);

    qdrant()->upsert('docs', [
        new VectorRecord('id-1', [0.1, 0.2], ['tenant_id' => 't1', 'content' => 'hello']),
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_contains($request->url(), '/collections/docs/points')
        && $request['points'][0]['id'] === 'id-1'
        && $request['points'][0]['payload']['content'] === 'hello');
});

it('searches and maps results with tenant filter (FR-RT-04)', function () {
    Http::fake(['*/points/search' => Http::response([
        'result' => [
            ['id' => 'c1', 'score' => 0.92, 'payload' => ['content' => 'alpha', 'tenant_id' => 't1', 'document_id' => 'd1', 'chunk_id' => 'c1'], 'vector' => [0.1, 0.2]],
        ],
    ])]);

    $hits = qdrant()->search('docs', [0.1, 0.2], new RetrievalQuery('q', topK: 5, filters: ['tag' => 'x'], tenantId: 't1'));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->id)->toBe('c1')
        ->and($hits[0]->score)->toBe(0.92)
        ->and($hits[0]->content)->toBe('alpha')
        ->and($hits[0]->vector)->toBe([0.1, 0.2]);

    Http::assertSent(function ($request) {
        $must = $request['filter']['must'] ?? [];
        $keys = array_column($must, 'key');

        return in_array('tenant_id', $keys, true) && in_array('tag', $keys, true);
    });
});

it('translates range and list filters', function () {
    Http::fake(['*/points/search' => Http::response(['result' => []])]);

    qdrant()->search('docs', [0.1, 0.2], new RetrievalQuery('q', filters: ['year' => ['gte' => 2024], 'tag' => ['a', 'b']]));

    Http::assertSent(function ($request) {
        $must = $request['filter']['must'];
        $byKey = [];
        foreach ($must as $c) {
            $byKey[$c['key']] = $c;
        }

        return ($byKey['year']['range']['gte'] ?? null) === 2024
            && ($byKey['tag']['match']['any'] ?? null) === ['a', 'b'];
    });
});

it('throws on a failed search', function () {
    Http::fake(['*/points/search' => Http::response('boom', 500)]);

    qdrant()->search('docs', [0.1], new RetrievalQuery('q'));
})->throws(RagException::class, 'Qdrant search failed');

it('counts points and deletes by filter', function () {
    Http::fake([
        '*/points/count' => Http::response(['result' => ['count' => 42]]),
        '*/points/delete*' => Http::response(['result' => ['status' => 'completed']]),
    ]);

    expect(qdrant()->count('docs'))->toBe(42);

    qdrant()->deleteByFilter('docs', ['document_id' => 'd1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/points/delete')
        && ($request['filter']['must'][0]['key'] ?? null) === 'document_id');
});

it('resolves through the manager and reports its name', function () {
    config()->set('rag-engine.vector_stores.qdrant.host', 'http://localhost:6333');

    expect(app(VectorStoreManager::class)->driver('qdrant')->name())->toBe('qdrant');
});
