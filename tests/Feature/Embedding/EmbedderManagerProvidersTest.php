<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Sellinnate\RagEngine\Embedding\OpenAIEmbedder;
use Sellinnate\RagEngine\Managers\EmbedderManager;

it('resolves every built-in provider driver through config', function (string $name) {
    config()->set("rag-engine.embedders.{$name}.api_key", 'test-key');
    if ($name === 'azure-openai') {
        config()->set('rag-engine.embedders.azure-openai.base_url', 'https://res.openai.azure.com');
    }

    // Real providers are wrapped in the caching decorator; resolution must not error.
    $driver = app(EmbedderManager::class)->driver($name);

    expect($driver)->toBeInstanceOf(CachingEmbedder::class)
        ->and($driver->dimensions())->toBeGreaterThan(0)
        ->and($driver->model())->toBeString();
})->with(['openai', 'azure-openai', 'mistral', 'jina', 'voyage', 'cohere', 'gemini', 'huggingface', 'ollama']);

it('can disable caching/retry decorators per provider', function () {
    config()->set('rag-engine.embedders.openai.api_key', 'k');
    config()->set('rag-engine.embedders.openai.cache', false);
    config()->set('rag-engine.embedders.openai.retries', false);

    $driver = app(EmbedderManager::class)->driver('openai');

    expect($driver)->toBeInstanceOf(OpenAIEmbedder::class);
});
