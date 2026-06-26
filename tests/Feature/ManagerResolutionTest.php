<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Embedding\FakeEmbedder;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\VectorStore\InMemoryVectorStore;

it('resolves the default embedder driver', function () {
    expect(app(EmbedderManager::class)->driver())->toBeInstanceOf(FakeEmbedder::class);
});

it('caches the resolved driver instance', function () {
    $manager = app(VectorStoreManager::class);

    expect($manager->driver())->toBe($manager->driver());
});

it('resolves the default vector store as in-memory', function () {
    expect(app(VectorStoreManager::class)->driver())->toBeInstanceOf(InMemoryVectorStore::class);
});

it('throws for an unconfigured connection name', function () {
    app(EmbedderManager::class)->driver('does-not-exist');
})->throws(RagException::class, 'not configured');

it('throws for a configured but unsupported driver type', function () {
    config()->set('rag-engine.embedders.weird', ['driver' => 'unsupported']);

    app(EmbedderManager::class)->driver('weird');
})->throws(RagException::class, 'not supported');

it('lets consumers register a custom driver via extend (FR-EV-04)', function () {
    config()->set('rag-engine.embedders.custom', ['driver' => 'custom', 'dimensions' => 4]);

    $manager = app(EmbedderManager::class);
    $manager->extend('custom', fn (array $config) => new FakeEmbedder(dimensions: (int) $config['dimensions']));

    expect($manager->driver('custom'))->toBeInstanceOf(FakeEmbedder::class)
        ->and($manager->driver('custom')->dimensions())->toBe(4);
});

it('forgetDrivers clears the resolution cache', function () {
    $manager = app(VectorStoreManager::class);
    $first = $manager->driver();
    $manager->forgetDrivers();

    expect($manager->driver())->not->toBe($first);
});

it('binds the default drivers to their contracts', function () {
    expect(app(Embedder::class))->toBeInstanceOf(FakeEmbedder::class)
        ->and(app(VectorStore::class))->toBeInstanceOf(InMemoryVectorStore::class);
});

it('resolves the local kms driver by default', function () {
    expect(app(KmsManager::class)->driver()->name())->toBe('local');
});
