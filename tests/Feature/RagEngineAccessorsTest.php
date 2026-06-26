<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\RagEngine;

beforeEach(fn () => $this->engine = app(RagEngine::class));

it('exposes every resolved driver through the engine', function () {
    expect($this->engine->vectorStore())->toBeInstanceOf(VectorStore::class)
        ->and($this->engine->reranker())->toBeInstanceOf(Reranker::class)
        ->and($this->engine->kms())->toBeInstanceOf(KeyManagement::class)
        ->and($this->engine->tokenizer())->toBeInstanceOf(Tokenizer::class)
        ->and($this->engine->llm())->toBeInstanceOf(Llm::class);
});

it('resolves the null llm and reranker via their managers', function () {
    expect(app(LlmManager::class)->driver()->model())->toBe('null')
        ->and(app(RerankerManager::class)->driver()->name())->toBe('null');
});
