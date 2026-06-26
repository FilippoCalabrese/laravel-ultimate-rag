<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;

beforeEach(function () {
    $this->store = new InMemoryVectorStore;
    $this->store->createNamespace('docs', 3, 'cosine');
});

it('creates namespaces and reports existence', function () {
    expect($this->store->namespaceExists('docs'))->toBeTrue()
        ->and($this->store->namespaceExists('missing'))->toBeFalse();
});

it('rejects unsupported metrics on namespace creation', function () {
    $this->store->createNamespace('bad', 3, 'manhattan');
})->throws(RagException::class, 'metric');

it('upserts idempotently by id (FR-VS-12)', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0])]);
    $this->store->upsert('docs', [new VectorRecord('a', [0.0, 1.0, 0.0])]);

    expect($this->store->count('docs'))->toBe(1);
});

it('enforces vector dimensionality on upsert', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0])]);
})->throws(RagException::class, 'dimension');

it('ranks by cosine similarity, nearest first', function () {
    $this->store->upsert('docs', [
        new VectorRecord('near', [1.0, 0.0, 0.0]),
        new VectorRecord('far', [0.0, 0.0, 1.0]),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10));

    expect($hits[0]->id)->toBe('near')
        ->and($hits[0]->score)->toBeGreaterThan($hits[1]->score);
});

it('honours top-k', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0]),
        new VectorRecord('b', [0.9, 0.1, 0.0]),
        new VectorRecord('c', [0.8, 0.2, 0.0]),
    ]);

    expect($this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 2)))
        ->toHaveCount(2);
});

it('applies a score threshold (FR-RT-02)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('near', [1.0, 0.0, 0.0]),
        new VectorRecord('orthogonal', [0.0, 1.0, 0.0]),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, scoreThreshold: 0.5));

    expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('near');
});

it('filters by exact metadata match (FR-VS-08)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0], ['tag' => 'public']),
        new VectorRecord('b', [1.0, 0.0, 0.0], ['tag' => 'private']),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['tag' => 'public']));

    expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('a');
});

it('supports operator and list filters', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0], ['year' => 2020]),
        new VectorRecord('b', [1.0, 0.0, 0.0], ['year' => 2024]),
        new VectorRecord('c', [1.0, 0.0, 0.0], ['year' => 2025]),
    ]);

    $gte = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['year' => ['gte' => 2024]]));
    $in = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['year' => [2020, 2025]]));

    expect($gte)->toHaveCount(2)->and($in)->toHaveCount(2);
});

it('scopes search to the tenant id from the query (FR-MT-02)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('t1', [1.0, 0.0, 0.0], ['tenant_id' => 't1']),
        new VectorRecord('t2', [1.0, 0.0, 0.0], ['tenant_id' => 't2']),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', tenantId: 't1'));

    expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('t1');
});

it('deletes by id and by filter', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1']),
        new VectorRecord('b', [1.0, 0.0, 0.0], ['tenant_id' => 't2']),
    ]);

    $this->store->delete('docs', ['a']);
    expect($this->store->count('docs'))->toBe(1);

    $this->store->deleteByFilter('docs', ['tenant_id' => 't2']);
    expect($this->store->count('docs'))->toBe(0);
});

it('supports dot and l2 metrics', function () {
    foreach (['dot', 'l2'] as $metric) {
        $store = new InMemoryVectorStore;
        $store->createNamespace('n', 3, $metric);
        $store->upsert('n', [
            new VectorRecord('near', [1.0, 0.0, 0.0]),
            new VectorRecord('far', [-1.0, 0.0, 0.0]),
        ]);

        $hits = $store->search('n', [1.0, 0.0, 0.0], new RetrievalQuery('q'));
        expect($hits[0]->id)->toBe('near');
    }
});

it('searching a missing namespace returns empty', function () {
    expect($this->store->search('nope', [1.0, 0.0, 0.0], new RetrievalQuery('q')))->toBe([]);
});

it('deletes a namespace', function () {
    $this->store->deleteNamespace('docs');
    expect($this->store->namespaceExists('docs'))->toBeFalse();
});

it('reports its driver name', function () {
    expect($this->store->name())->toBe('memory');
});

it('supports eq/neq/nin operator filters', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0], ['env' => 'prod']),
        new VectorRecord('b', [1.0, 0.0, 0.0], ['env' => 'staging']),
        new VectorRecord('c', [1.0, 0.0, 0.0], ['env' => 'dev']),
    ]);

    $eq = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['env' => ['eq' => 'prod']]));
    $neq = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['env' => ['neq' => 'prod']]));
    $nin = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['env' => ['nin' => ['dev', 'staging']]]));
    $inOp = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['env' => ['in' => ['prod', 'dev']]]));

    expect($eq)->toHaveCount(1)
        ->and($neq)->toHaveCount(2)
        ->and($nin)->toHaveCount(1)->and($nin[0]->id)->toBe('a')
        ->and($inOp)->toHaveCount(2);
});

it('throws on an unsupported filter operator', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0], ['n' => 1])]);

    $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['n' => ['like' => 1]]));
})->throws(RagException::class, 'operator');

it('does not leak across tenants with numeric-string ids (strict compare, H1)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => '100']),
        new VectorRecord('b', [1.0, 0.0, 0.0], ['tenant_id' => '1e2']), // == '100' under loose compare
        new VectorRecord('c', [1.0, 0.0, 0.0], ['tenant_id' => '1']),
        new VectorRecord('d', [1.0, 0.0, 0.0], ['tenant_id' => '01']),  // == '1' under loose compare
    ]);

    $hits100 = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', tenantId: '100'));
    $hits1 = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', tenantId: '1'));

    expect($hits100)->toHaveCount(1)->and($hits100[0]->id)->toBe('a')
        ->and($hits1)->toHaveCount(1)->and($hits1[0]->id)->toBe('c');
});

it('does not match records missing the filtered key (null coercion, M3)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('has', [1.0, 0.0, 0.0], ['tenant_id' => '0']),
        new VectorRecord('missing', [1.0, 0.0, 0.0], []),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', filters: ['tenant_id' => '0']));

    expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('has');
});

it('auto-creates a namespace on upsert when missing', function () {
    $this->store->upsert('fresh', [new VectorRecord('a', [1.0, 2.0])]);

    expect($this->store->count('fresh'))->toBe(1);
});
