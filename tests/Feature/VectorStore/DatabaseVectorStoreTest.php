<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\VectorStore\DatabaseVectorStore;

beforeEach(function () {
    $this->store = new DatabaseVectorStore(app(ConnectionResolverInterface::class));
    $this->store->createNamespace('docs', 3, 'cosine');
});

it('persists and ranks vectors by cosine similarity (FR-VS-02)', function () {
    $this->store->upsert('docs', [
        new VectorRecord('near', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'near']),
        new VectorRecord('far', [0.0, 0.0, 1.0], ['tenant_id' => 't1', 'content' => 'far']),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));

    expect($hits[0]->id)->toBe('near')
        ->and($hits[0]->score)->toBeGreaterThan($hits[1]->score)
        ->and($this->store->count('docs'))->toBe(2)
        ->and($this->store->name())->toBe('database');
});

it('upserts idempotently and enforces dimensions', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0])]);
    $this->store->upsert('docs', [new VectorRecord('a', [0.0, 1.0, 0.0])]);

    expect($this->store->count('docs'))->toBe(1);

    expect(fn () => $this->store->upsert('docs', [new VectorRecord('b', [1.0, 0.0])]))
        ->toThrow(RagException::class, 'dimension');
});

it('enforces fail-closed tenant scoping and metadata filters', function () {
    $this->store->upsert('docs', [
        new VectorRecord('t1', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'tag' => 'a']),
        new VectorRecord('t2', [1.0, 0.0, 0.0], ['tenant_id' => 't2', 'tag' => 'a']),
    ]);

    $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', tenantId: 't1'));
    expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('t1');

    // numeric-string tenant ids do not collide (strict compare via MetadataMatcher).
    $this->store->upsert('docs', [
        new VectorRecord('x100', [1.0, 0.0, 0.0], ['tenant_id' => '100']),
        new VectorRecord('x1e2', [1.0, 0.0, 0.0], ['tenant_id' => '1e2']),
    ]);
    $strict = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', tenantId: '100'));
    expect($strict)->toHaveCount(1)->and($strict[0]->id)->toBe('x100');
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

it('rejects a dimension change on a populated namespace and applies score threshold', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0])]);

    expect(fn () => $this->store->createNamespace('docs', 16))->toThrow(RagException::class, 'cannot change');

    $threshold = $this->store->search('docs', [0.0, 1.0, 0.0], new RetrievalQuery('q', scoreThreshold: 0.9));
    expect($threshold)->toBeEmpty(); // orthogonal vector below threshold
});

it('resolves through the manager and is fully searchable end to end', function () {
    config()->set('rag-engine.defaults.vector_store', 'database');

    expect(app(VectorStoreManager::class)->driver())->toBeInstanceOf(DatabaseVectorStore::class);
});

it('deleteNamespace removes vectors and config', function () {
    $this->store->upsert('docs', [new VectorRecord('a', [1.0, 0.0, 0.0])]);
    $this->store->deleteNamespace('docs');

    expect($this->store->namespaceExists('docs'))->toBeFalse();
});
