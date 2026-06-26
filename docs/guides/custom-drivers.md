---
title: "Custom drivers"
description: "Register your own embedder, vector store, reranker or KMS driver."
---

# Writing a custom driver

Every subsystem is extensible without forking the package. You implement the
relevant contract and register it with the matching manager's `extend()` method.

## Example: a custom embedder

```php
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;

final class AcmeEmbedder implements Embedder
{
    public function __construct(private string $apiKey, private int $dimensions) {}

    public function embed(array $texts): EmbeddingResponse
    {
        $vectors = /* call your provider, returns list<list<float>> */;

        return new EmbeddingResponse($vectors, 'acme-v1', $this->dimensions, new Usage(tokens: 123));
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int { return $this->dimensions; }
    public function model(): string { return 'acme-v1'; }
}
```

Register it — typically in a service provider's `boot()`:

```php
use Sellinnate\RagEngine\Managers\EmbedderManager;

$this->app->make(EmbedderManager::class)->extend(
    'acme',
    fn (array $config) => new AcmeEmbedder($config['api_key'], $config['dimensions']),
);
```

Then point a config connection at it:

```php
// config/rag-engine.php
'embedders' => [
    'acme' => ['driver' => 'acme', 'api_key' => env('ACME_KEY'), 'dimensions' => 1024],
],
'defaults' => ['embedder' => 'acme'],
```

::: callout tip "The same pattern everywhere"
`VectorStoreManager`, `RerankerManager`, `KmsManager`, `TokenizerManager` and
`LlmManager` all expose the identical `extend($driver, $factory)` API. Custom
parsers and chunkers register the same way against their managers.
:::

## Why this works

The base `DriverManager` resolves a config block, reads its `driver` key, and
looks for either a registered custom creator or a `create<Driver>Driver()`
method. Resolved drivers are cached per connection name. This is the standard
Laravel manager pattern, applied uniformly across the engine.
