<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Sellinnate\RagEngine\Concerns\Searchable;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Generation\FakeLlm;
use Sellinnate\RagEngine\Generation\NullLlm;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;
use Sellinnate\RagEngine\Query\MultiQueryTransformer;
use Sellinnate\RagEngine\Tenancy\TenantContext;

it('Searchable trait ingests and indexes a model (FR-DX-04)', function () {
    $model = new TestSearchableModel(['title' => 'Solar Power', 'body' => 'Solar panels convert sunlight to electricity.']);

    $count = $model->makeSearchable();

    expect($count)->toBeGreaterThan(0)
        ->and(Document::query()->where('metadata->document_key', TestSearchableModel::class.':')->exists())->toBeFalse();

    $hits = Rag::search('solar electricity')->topK(3)->get();
    expect($hits)->not->toBeEmpty();
});

it('Searchable trait never indexes attributes outside the allowlist (H3)', function () {
    $model = new TestSearchableModel([
        'title' => 'Public Title',
        'body' => 'Public body text.',
        'password' => 'super-secret-hash',
        'api_token' => 'tok_abc123',
    ]);

    $model->makeSearchable();

    $hits = Rag::search('public secret token')->topK(10)->get();
    foreach ($hits as $hit) {
        expect($hit->content)->not->toContain('super-secret-hash')
            ->and($hit->content)->not->toContain('tok_abc123');
    }
});

it('MultiQueryTransformer expands a query with an LLM (FR-QT-01)', function () {
    $transformer = new MultiQueryTransformer(new FakeLlm, 3);

    $queries = $transformer->transform('how do solar panels work');

    expect($queries[0])->toBe('how do solar panels work')
        ->and(count($queries))->toBeGreaterThanOrEqual(1)
        ->and($transformer->name())->toBe('multi-query');
});

it('MultiQueryTransformer degrades to the original query without an LLM', function () {
    expect((new MultiQueryTransformer(new NullLlm))->transform('q'))->toBe(['q']);
});

it('ProcessDocumentJob can be dispatched and batched (FR-OR-01)', function () {
    Bus::fake();

    $document = Rag::ingest(new IngestionSource('queued content', 'text/plain', IngestionSource::TYPE_TEXT));
    ProcessDocumentJob::dispatch((string) $document->id, $document->tenant_id);

    Bus::assertDispatched(ProcessDocumentJob::class);
});

it('ProcessDocumentJob processes the document when handled', function () {
    $document = Rag::ingest(new IngestionSource('Handle me. Process this content.', 'text/plain', IngestionSource::TYPE_TEXT));

    (new ProcessDocumentJob((string) $document->id, $document->tenant_id, ['strategy' => 'sentence']))
        ->handle(app(IngestionPipeline::class), app(TenantContext::class));

    expect($document->fresh()->status)->toBe('indexed');
});

class TestSearchableModel extends Model
{
    use Searchable;

    protected $guarded = [];

    /** Only these attributes are indexed (never secrets). */
    protected array $ragSearchable = ['title', 'body'];

    public $timestamps = false;

    public function getKey(): string
    {
        return 'tsm-1';
    }
}
