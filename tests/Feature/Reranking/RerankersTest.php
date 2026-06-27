<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Reranking\CohereReranker;
use Sellinnate\RagEngine\Reranking\JinaReranker;

function rerankHttp(): HttpFactory
{
    return app(HttpFactory::class);
}

/** @return list<SearchHit> */
function sampleHits(): array
{
    return [
        new SearchHit('a', 0.10, 'The cat sat on the mat.'),
        new SearchHit('b', 0.20, 'Photovoltaic panels convert sunlight to electricity.'),
        new SearchHit('c', 0.30, 'Refunds take 14 days.'),
    ];
}

it('CohereReranker reorders and rescores hits, sends the right request', function () {
    // Provider says index 1 is most relevant, then 2, then 0.
    Http::fake(['*/v2/rerank' => Http::response([
        'results' => [
            ['index' => 1, 'relevance_score' => 0.99],
            ['index' => 2, 'relevance_score' => 0.50],
            ['index' => 0, 'relevance_score' => 0.01],
        ],
    ])]);

    $reranker = new CohereReranker(rerankHttp(), 'rerank-v3.5', 'co-key', 'https://api.cohere.com');
    $out = $reranker->rerank('solar energy', sampleHits(), 2);

    expect($out)->toHaveCount(2)
        ->and($out[0]->id)->toBe('b')
        ->and($out[0]->score)->toBe(0.99)
        ->and($out[1]->id)->toBe('c')
        ->and($reranker->name())->toBe('cohere');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v2/rerank')
        && $r->hasHeader('Authorization', 'Bearer co-key')
        && $r['model'] === 'rerank-v3.5'
        && $r['query'] === 'solar energy'
        && $r['top_n'] === 2
        && $r['documents'][1] === 'Photovoltaic panels convert sunlight to electricity.');
});

it('JinaReranker posts to /v1/rerank and maps results', function () {
    Http::fake(['*/v1/rerank' => Http::response([
        'results' => [
            ['index' => 2, 'relevance_score' => 0.88],
            ['index' => 0, 'relevance_score' => 0.10],
        ],
    ])]);

    $out = (new JinaReranker(rerankHttp(), 'jina-reranker-v2-base-multilingual', 'jk', 'https://api.jina.ai'))
        ->rerank('refunds', sampleHits(), 5);

    expect($out[0]->id)->toBe('c')->and($out[0]->score)->toBe(0.88);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/rerank') && $r->hasHeader('Authorization', 'Bearer jk'));
});

it('a reranker returns an empty array for no hits without calling the API', function () {
    Http::fake();

    $out = (new CohereReranker(rerankHttp(), 'rerank-v3.5', 'k', 'https://api.cohere.com'))->rerank('q', [], 5);

    expect($out)->toBe([]);
    Http::assertNothingSent();
});

it('a reranker raises a RagException on a provider error', function () {
    Http::fake(['*/v2/rerank' => Http::response('rate limited', 429)]);

    (new CohereReranker(rerankHttp(), 'rerank-v3.5', 'k', 'https://api.cohere.com'))->rerank('q', sampleHits(), 3);
})->throws(RagException::class, 'cohere');

it('RerankerManager resolves the cohere and jina drivers from config', function () {
    config()->set('rag-engine.rerankers.cohere', ['driver' => 'cohere', 'api_key' => 'k']);
    config()->set('rag-engine.rerankers.jina', ['driver' => 'jina', 'api_key' => 'k']);

    $manager = app(RerankerManager::class)->forgetDrivers();

    expect($manager->driver('cohere'))->toBeInstanceOf(CohereReranker::class)
        ->and($manager->driver('jina'))->toBeInstanceOf(JinaReranker::class);
});
