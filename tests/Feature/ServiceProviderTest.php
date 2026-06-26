<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\RagEngine;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;

it('publishes the config under the rag-engine key', function () {
    expect(config('rag-engine.defaults.embedder'))->toBe('fake')
        ->and(config('rag-engine.tables.documents'))->toBe('rag_documents');
});

it('registers the RagEngine singleton and aliases it', function () {
    expect(app(RagEngine::class))->toBeInstanceOf(RagEngine::class)
        ->and(app('rag-engine'))->toBe(app(RagEngine::class));
});

it('exposes drivers through the engine', function () {
    $engine = app(RagEngine::class);

    expect($engine->embedder())->toBeInstanceOf(Embedder::class)
        ->and($engine->encrypter())->toBeInstanceOf(EnvelopeEncrypter::class)
        ->and($engine->tenant()->id())->toBe('default');
});

it('works through the Rag facade end to end (encrypt/decrypt)', function () {
    $payload = Rag::encrypter()->encrypt('secret via facade', 'tenant-1');

    expect(Rag::encrypter()->decrypt($payload))->toBe('secret via facade');
});

it('scopes a closure to a tenant via the facade', function () {
    $seen = Rag::forTenant('tenant-7', fn () => Rag::tenant()->id());

    expect($seen)->toBe('tenant-7')
        ->and(Rag::tenant()->id())->toBe('default');
});

it('keeps config cache safe: config contains no closures', function () {
    $config = config('rag-engine');

    array_walk_recursive($config, function ($value) {
        expect($value)->not->toBeInstanceOf(Closure::class);
    });
});
