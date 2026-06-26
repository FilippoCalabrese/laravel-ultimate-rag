---
title: "Contracts reference"
description: "The public interfaces that make up the engine's stable API."
---

# Contracts reference

All contracts live in the `Sellinnate\RagEngine\Contracts` namespace and follow
SemVer: they do not change without a major release.

::: callout info "What this page is for"
A **contract** is a PHP `interface`. Each one lists the methods a driver must
implement. You read this page when you want to **write your own driver** (see
**[Custom drivers](/guides/custom-drivers)**) — it's the checklist of methods to
implement. For everyday use you call the `Rag` facade, not these interfaces
directly.
:::

## Embedder

```php
interface Embedder
{
    public function embed(array $texts): EmbeddingResponse;   // @param list<string>
    public function embedOne(string $text): EmbeddingResponse;
    public function dimensions(): int;
    public function model(): string;
}
```

## VectorStore

```php
interface VectorStore
{
    public function createNamespace(string $namespace, int $dimensions, string $metric = 'cosine'): void;
    public function namespaceExists(string $namespace): bool;
    public function deleteNamespace(string $namespace): void;
    public function upsert(string $namespace, array $records): void;          // list<VectorRecord>
    public function search(string $namespace, array $vector, RetrievalQuery $query): array; // list<SearchHit>
    public function delete(string $namespace, array $ids): void;
    public function deleteByFilter(string $namespace, array $filter): void;
    public function count(string $namespace): int;
    public function name(): string;
}
```

## Embeddable

Implemented by Eloquent models that should be indexed (see
**[Embedding Eloquent models](/concepts/eloquent-models)**). The single method
declares *what* is embedded; the `HasEmbeddings` trait supplies identity and
sync.

```php
interface Embeddable
{
    public function toEmbeddable(): EmbeddableDefinition;
}
```

`EmbeddableDefinition` (in `Sellinnate\RagEngine\Eloquent`) is a fluent builder:
`add()` / `text()` (text parts), `include()` / `includeMany()` (related
embeddables — recursive), `metadata()`, `documentKey()`, `options()`.

## KeyManagement

```php
interface KeyManagement
{
    public function createKey(string $keyId): void;
    public function generateDataKey(string $keyId): GeneratedDataKey;
    public function unwrapDataKey(string $keyId, string $wrappedKey): string;
    public function wrapDataKey(string $keyId, string $plaintext): string;   // re-wrap on rotation
    public function rotateKey(string $keyId): void;
    public function destroyKey(string $keyId): void;   // crypto-shredding
    public function keyExists(string $keyId): bool;
    public function name(): string;
}
```

## Others

| Contract | Core method |
|---|---|
| `Parser` | `parse(string $contents, string $mimeType, array $context = []): ParsedDocument` |
| `Chunker` | `chunk(ParsedDocument $document, array $options = []): array` |
| `Tokenizer` | `count(string $text): int` / `truncate(string $text, int $maxTokens): string` |
| `Reranker` | `rerank(string $query, array $hits, int $topK): array` |
| `QueryTransformer` | `transform(string $query): array` |
| `Llm` | `generate(string $prompt, array $options = []): string` |

## Lifecycle events

Dispatched across the pipeline (`Sellinnate\RagEngine\Events`): `DocumentIngested`,
`DocumentChunked`, `ChunksEmbedded`, `DocumentIndexed`, `SearchPerformed`,
`IngestionFailed`, `KeyRotated`, `DataShredded`.
