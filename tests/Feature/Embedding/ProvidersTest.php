<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Sellinnate\RagEngine\Embedding\AzureOpenAIEmbedder;
use Sellinnate\RagEngine\Embedding\CohereEmbedder;
use Sellinnate\RagEngine\Embedding\GeminiEmbedder;
use Sellinnate\RagEngine\Embedding\HuggingFaceEmbedder;
use Sellinnate\RagEngine\Embedding\JinaEmbedder;
use Sellinnate\RagEngine\Embedding\OpenAIEmbedder;
use Sellinnate\RagEngine\Embedding\VoyageEmbedder;
use Sellinnate\RagEngine\Exceptions\EmbeddingException;
use Sellinnate\RagEngine\Exceptions\RagException;

function http(): HttpFactory
{
    return app(HttpFactory::class);
}

function openAiResponse(array $vectors, int $tokens = 100): array
{
    $data = [];
    foreach ($vectors as $i => $v) {
        $data[] = ['index' => $i, 'embedding' => $v];
    }

    return ['data' => $data, 'usage' => ['total_tokens' => $tokens]];
}

it('OpenAIEmbedder sends the dimensions param for v3 models and Bearer auth (FR-EM-03)', function () {
    Http::fake(['*/embeddings' => Http::response(openAiResponse([[0.1, 0.2, 0.3]], 500))]);

    $embedder = new OpenAIEmbedder(http(), 'text-embedding-3-small', 3, 'https://api.openai.com/v1', 'sk-key', costPer1kTokens: 0.00002, options: ['organization' => 'org-1']);
    $response = $embedder->embed(['hello']);

    expect($response->vectorAt(0))->toBe([0.1, 0.2, 0.3])
        ->and($response->usage->tokens)->toBe(500)
        ->and($response->usage->cost)->toBe(0.00001);

    Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-key')
        && $r->hasHeader('OpenAI-Organization', 'org-1')
        && $r['model'] === 'text-embedding-3-small'
        && $r['dimensions'] === 3
        && $r['input'] === ['hello']);
});

it('OpenAIEmbedder omits dimensions for ada-002', function () {
    Http::fake(['*/embeddings' => Http::response(openAiResponse([[0.1, 0.2]]))]);

    (new OpenAIEmbedder(http(), 'text-embedding-ada-002', 2, 'https://api.openai.com/v1', 'k'))->embed(['x']);

    Http::assertSent(fn ($r) => ! isset($r['dimensions']));
});

it('OpenAiCompatible re-orders vectors by the response index', function () {
    // Return data out of order; the embedder must restore input order.
    Http::fake(['*/embeddings' => Http::response([
        'data' => [
            ['index' => 1, 'embedding' => [9.0]],
            ['index' => 0, 'embedding' => [1.0]],
        ],
        'usage' => ['total_tokens' => 10],
    ])]);

    $r = (new OpenAIEmbedder(http(), 'text-embedding-3-small', 1, 'https://api.openai.com/v1', 'k'))->embed(['a', 'b']);

    expect($r->vectorAt(0))->toBe([1.0])->and($r->vectorAt(1))->toBe([9.0]);
});

it('AzureOpenAIEmbedder uses the deployment URL, api-key header and no model field (FR-EM-01)', function () {
    Http::fake(['*' => Http::response(openAiResponse([[0.5, 0.6]]))]);

    $embedder = new AzureOpenAIEmbedder(http(), 'text-embedding-3-small', 2, 'https://res.openai.azure.com', 'azkey', options: ['deployment' => 'my-deploy', 'api_version' => '2024-02-01']);
    $embedder->embed(['hi']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/openai/deployments/my-deploy/embeddings')
        && str_contains($r->url(), 'api-version=2024-02-01')
        && $r->hasHeader('api-key', 'azkey')
        && ! isset($r['model'])
        && $r['dimensions'] === 2);
});

it('AzureOpenAIEmbedder requires deployment + api_version', function () {
    $embedder = new AzureOpenAIEmbedder(http(), 'm', 2, 'https://res.openai.azure.com', 'k');
    $embedder->embed(['x']);
})->throws(RagException::class, 'deployment');

it('JinaEmbedder sends dimensions + task for v3 (EU)', function () {
    Http::fake(['*/embeddings' => Http::response(openAiResponse([[0.1, 0.2]]))]);

    (new JinaEmbedder(http(), 'jina-embeddings-v3', 2, 'https://api.jina.ai/v1', 'jk', options: ['task' => 'retrieval.passage']))->embed(['x']);

    Http::assertSent(fn ($r) => $r['dimensions'] === 2 && $r['task'] === 'retrieval.passage');
});

it('VoyageEmbedder sends input_type and output_dimension', function () {
    Http::fake(['*/embeddings' => Http::response(openAiResponse([[0.1]]))]);

    (new VoyageEmbedder(http(), 'voyage-3', 1, 'https://api.voyageai.com/v1', 'vk', options: ['input_type' => 'document']))->embed(['x']);

    Http::assertSent(fn ($r) => $r['input_type'] === 'document' && $r['output_dimension'] === 1);
});

it('CohereEmbedder uses texts + input_type and extracts embeddings.float + billed tokens', function () {
    Http::fake(['*/v2/embed' => Http::response([
        'embeddings' => ['float' => [[0.1, 0.2], [0.3, 0.4]]],
        'meta' => ['billed_units' => ['input_tokens' => 42]],
    ])]);

    $embedder = new CohereEmbedder(http(), 'embed-multilingual-v3.0', 2, 'https://api.cohere.com', 'ck', options: ['input_type' => 'search_query']);
    $r = $embedder->embed(['a', 'b']);

    expect($r)->toHaveCount(2)
        ->and($r->vectorAt(1))->toBe([0.3, 0.4])
        ->and($r->usage->tokens)->toBe(42);

    Http::assertSent(fn ($req) => $req['texts'] === ['a', 'b']
        && $req['input_type'] === 'search_query'
        && $req['embedding_types'] === ['float']);
});

it('CohereEmbedder reads a v1 bare-list response, estimates tokens and sends output_dimension', function () {
    Http::fake(['*/v2/embed' => Http::response([
        'embeddings' => [[0.1, 0.2], [0.3, 0.4]], // v1 shape: bare list, no meta.billed_units
    ])]);

    $embedder = new CohereEmbedder(http(), 'embed-english-v3.0', 2, 'https://api.cohere.com', 'ck', options: ['output_dimension' => 2]);
    $r = $embedder->embed(['a', 'b']);

    expect($r->vectorAt(0))->toBe([0.1, 0.2])
        ->and($r->usage->tokens)->toBeGreaterThan(0); // estimated, since no billed_units

    Http::assertSent(fn ($req) => $req['output_dimension'] === 2);
});

it('GeminiEmbedder batches contents, uses x-goog-api-key and extracts values', function () {
    Http::fake(['*:batchEmbedContents' => Http::response([
        'embeddings' => [['values' => [0.1, 0.2]], ['values' => [0.3, 0.4]]],
    ])]);

    $embedder = new GeminiEmbedder(http(), 'text-embedding-004', 2, 'https://generativelanguage.googleapis.com', 'gk');
    $r = $embedder->embed(['a', 'b']);

    expect($r)->toHaveCount(2)->and($r->vectorAt(0))->toBe([0.1, 0.2]);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v1beta/models/text-embedding-004:batchEmbedContents')
        && $req->hasHeader('x-goog-api-key', 'gk')
        && $req['requests'][0]['content']['parts'][0]['text'] === 'a'
        && $req['requests'][0]['outputDimensionality'] === 2);
});

it('HuggingFaceEmbedder posts to feature-extraction and maps the 2D array', function () {
    Http::fake(['*/pipeline/feature-extraction/*' => Http::response([[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]])]);

    $embedder = new HuggingFaceEmbedder(http(), 'BAAI/bge-small-en-v1.5', 3, 'https://api-inference.huggingface.co', 'hf');
    $r = $embedder->embed(['a', 'b']);

    expect($r)->toHaveCount(2)->and($r->vectorAt(1))->toBe([0.4, 0.5, 0.6]);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/pipeline/feature-extraction/BAAI/bge-small-en-v1.5')
        && $req->hasHeader('Authorization', 'Bearer hf')
        && $req['inputs'] === ['a', 'b']);
});

it('a provider error raises a retryable/non-retryable EmbeddingException', function () {
    Http::fake(['*/embeddings' => Http::response('rate limited', 429)]);

    try {
        (new OpenAIEmbedder(http(), 'text-embedding-3-small', 2, 'https://api.openai.com/v1', 'k'))->embed(['x']);
        expect()->fail('expected an exception');
    } catch (EmbeddingException $e) {
        expect($e->retryable)->toBeTrue()->and($e->status)->toBe(429);
    }
});
