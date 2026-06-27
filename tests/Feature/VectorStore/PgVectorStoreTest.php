<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionResolverInterface;
use Sellinnate\RagEngine\Data\RetrievalQuery;
use Sellinnate\RagEngine\Data\VectorRecord;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\VectorStore\PgVectorStore;

/**
 * Native pgvector tests. The driver-resolution test runs everywhere; the
 * behavioural tests need a real Postgres with the `vector` extension and are
 * skipped unless RAG_PGVECTOR_TEST=1 (set in CI, which boots a pgvector service).
 */
function pgvectorConfigured(): bool
{
    return extension_loaded('pdo_pgsql') && getenv('RAG_PGVECTOR_TEST') === '1';
}

function pgStore(): PgVectorStore
{
    config()->set('database.connections.pgvector_test', [
        'driver' => 'pgsql',
        'host' => getenv('RAG_PGVECTOR_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('RAG_PGVECTOR_TEST_PORT') ?: '5432',
        'database' => getenv('RAG_PGVECTOR_TEST_DB') ?: 'rag',
        'username' => getenv('RAG_PGVECTOR_TEST_USER') ?: 'postgres',
        'password' => getenv('RAG_PGVECTOR_TEST_PASSWORD') ?: 'postgres',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]);

    return new PgVectorStore(
        app(ConnectionResolverInterface::class),
        connection: 'pgvector_test',
        table: 'rag_pgvectors_test',
        dimensions: 3,
        metric: 'cosine',
    );
}

it('resolves the pgvector driver to PgVectorStore (no DB needed)', function () {
    config()->set('rag-engine.defaults.vector_store', 'pgvector');

    expect(app(VectorStoreManager::class)->driver())->toBeInstanceOf(PgVectorStore::class);
});

it('rejects a dimension mismatch with a clear error', function () {
    // No DB connection is touched before the dimension check.
    pgStore()->createNamespace('docs', 8, 'cosine');
})->throws(RagException::class, 'dimensions');

describe('native pgvector (requires Postgres + vector extension)', function () {
    beforeEach(function () {
        if (! pgvectorConfigured()) {
            $this->markTestSkipped('Set RAG_PGVECTOR_TEST=1 with a reachable pgvector Postgres to run these.');
        }

        $this->store = pgStore();
        // Clean slate.
        app(ConnectionResolverInterface::class)->connection('pgvector_test')
            ->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        $this->store->createNamespace('docs', 3, 'cosine');
    });

    afterEach(function () {
        if (pgvectorConfigured()) {
            app(ConnectionResolverInterface::class)->connection('pgvector_test')
                ->statement('DROP TABLE IF EXISTS rag_pgvectors_test');
        }
    });

    it('upserts and ranks by cosine similarity using the native index', function () {
        $this->store->upsert('docs', [
            new VectorRecord('near', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'near']),
            new VectorRecord('far', [0.0, 0.0, 1.0], ['tenant_id' => 't1', 'content' => 'far']),
        ]);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));

        expect($hits[0]->id)->toBe('near')
            ->and($hits[0]->score)->toBeGreaterThan($hits[1]->score)
            ->and($hits[0]->vector)->toBe([1.0, 0.0, 0.0])
            ->and($this->store->count('docs'))->toBe(2)
            ->and($this->store->name())->toBe('pgvector');
    });

    it('scopes results to the tenant', function () {
        $this->store->upsert('docs', [
            new VectorRecord('t1doc', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'a']),
            new VectorRecord('t2doc', [1.0, 0.0, 0.0], ['tenant_id' => 't2', 'content' => 'b']),
        ]);

        $hits = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, tenantId: 't1'));

        expect($hits)->toHaveCount(1)->and($hits[0]->id)->toBe('t1doc');
    });

    it('applies metadata filters and deletes by filter', function () {
        $this->store->upsert('docs', [
            new VectorRecord('a', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'tag' => 'keep']),
            new VectorRecord('b', [0.9, 0.1, 0.0], ['tenant_id' => 't1', 'tag' => 'drop']),
        ]);

        $kept = $this->store->search('docs', [1.0, 0.0, 0.0], new RetrievalQuery('q', topK: 10, filters: ['tag' => 'keep'], tenantId: 't1'));
        expect($kept)->toHaveCount(1)->and($kept[0]->id)->toBe('a');

        $this->store->deleteByFilter('docs', ['tenant_id' => 't1']);
        expect($this->store->count('docs'))->toBe(0);
    });

    it('upsert is idempotent on the id (ON CONFLICT)', function () {
        $this->store->upsert('docs', [new VectorRecord('x', [1.0, 0.0, 0.0], ['tenant_id' => 't1', 'content' => 'v1'])]);
        $this->store->upsert('docs', [new VectorRecord('x', [0.0, 1.0, 0.0], ['tenant_id' => 't1', 'content' => 'v2'])]);

        expect($this->store->count('docs'))->toBe(1);
        $hits = $this->store->search('docs', [0.0, 1.0, 0.0], new RetrievalQuery('q', topK: 1, tenantId: 't1'));
        expect($hits[0]->content)->toBe('v2');
    });
});
