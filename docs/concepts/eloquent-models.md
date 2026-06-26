---
title: "Embedding Eloquent models"
description: "Make any Eloquent model embeddable via a contract — recursively, with automatic sync on change and full vector-to-model trace-back."
---

# Embedding Eloquent models

Index your domain models — not just files. A model declares **what** of itself
is embedded through one contract; the engine composes it (recursively, including
related models), keeps the index in sync as the model changes, and lets you walk
back from any retrieved vector to the originating model.

```php
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;

class Post extends Model implements Embeddable
{
    use HasEmbeddings;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->add('Body', $this->body)
            ->include($this->author, 'author')        // a related embeddable
            ->includeMany($this->comments, 'comments') // a collection of embeddables
            ->metadata(['kind' => 'post']);
    }
}
```

That's it. Save a `Post` and it is indexed; change it and it is re-indexed;
delete it and it is removed. `Rag::search(...)` now returns your models.

## The contract

An embeddable model implements **`Sellinnate\RagEngine\Contracts\Embeddable`**,
whose single method declares what is embedded:

```php
public function toEmbeddable(): EmbeddableDefinition;
```

The returned `EmbeddableDefinition` is the **single source of truth** for the
model's embedded representation:

| Method | Purpose |
|---|---|
| `add(string $label, $value)` | A labelled text part. Null/blank values are dropped. |
| `text($value)` | An unlabelled block of text. |
| `include(?Embeddable $related, ?string $as)` | Compose one related embeddable (recursive). Null is ignored. |
| `includeMany(iterable $related, ?string $as)` | Compose a collection of related embeddables. |
| `metadata(array $meta)` | Provenance metadata stored on the document. |
| `documentKey(string $key)` | Override the logical key (defaults to the model's `type:id`). |
| `options(array $opts)` | Per-model chunking/indexing options. |

::: callout tip "Allowlist, never every attribute"
Only the fields you `add()` are embedded — secrets (password hashes, tokens)
never reach the index or the LLM unless you explicitly put them there.
:::

## Recursive embedding

A model's embedding can **contain the embedding of its relations**. Anything you
`include()` / `includeMany()` is itself an `Embeddable`, composed into the
parent under a labelled section, recursively. Composition is bounded by
`rag-engine.eloquent.max_depth` (default 3) and is **cycle-safe** — a graph like
Post → Comment → Post terminates cleanly.

The composed document is what gets chunked and embedded, so a query matching a
comment or the author's bio still retrieves — and resolves to — the parent post.

## Keeping the index in sync

With `rag-engine.eloquent.auto_sync` on (the default), model events drive the
index:

- **created / updated / saved** → the model is (re)composed and re-indexed. A
  field change produces a new generation and the **previous vectors are purged**
  — no orphans, no stale results.
- **deleted** → the model is removed from the index.
- **restored** (soft deletes) → re-indexed.

Unchanged content is detected by checksum, so a save that doesn't affect the
embeddable representation is a cheap no-op.

You can always drive it manually:

```php
$post->syncEmbedding();   // (re)index now
$post->forgetEmbedding(); // remove now
```

### Related-model changes

When a **child** model is composed into a parent but is not a searchable
document itself, use `TouchesEmbeddingParents` so the parent re-indexes when the
child changes:

```php
use Sellinnate\RagEngine\Concerns\TouchesEmbeddingParents;

class Comment extends Model implements Embeddable
{
    use TouchesEmbeddingParents;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()->add('Comment', $this->body);
    }

    public function embeddingParents(): iterable
    {
        return [$this->post];
    }
}
```

::: callout info "Sync off the request in production"
Set `RAG_ELOQUENT_QUEUE=true` to push (re)indexing onto a queue
(`SyncModelEmbeddingJob`) instead of running it inline on the web request. Model
writes stay fast; the index catches up on a worker.
:::

## From a vector back to its model

Every model vector carries the model's morph identity (`embeddable_type` +
`embeddable_id`) right in its payload, so trace-back needs no second query:

```php
$hit = Rag::search('photovoltaic panels')->topK(5)->get()[0];

$model = Rag::models()->resolve($hit); // the originating Eloquent model, or null
```

`resolve()` is morph-map aware and returns `null` when the hit came from a
non-model source (a file or URL) or the model no longer exists.

## Configuration

```php
// config/rag-engine.php
'eloquent' => [
    'auto_sync' => env('RAG_ELOQUENT_AUTO_SYNC', true),
    'queue'     => env('RAG_ELOQUENT_QUEUE', false),
    'max_depth' => env('RAG_ELOQUENT_MAX_DEPTH', 3),
    'namespace' => env('RAG_ELOQUENT_NAMESPACE'), // null = share the default namespace
],
```

By default model embeddings share the global `namespace`, so `Rag::search()`
finds models and documents together. Point `RAG_ELOQUENT_NAMESPACE` at a separate
collection to keep them apart.

## Every chunk is traceable

This isn't limited to models. **Every** indexed vector — from a file, a URL or a
model — carries provenance in its payload so a hit always traces back to its
origin:

| Payload key | Meaning |
|---|---|
| `document_id` | The owning `Document` (always present). |
| `source_type` | `eloquent`, `url`, `upload`, `text`, `storage`. |
| `source_ref` | A human reference: the URL, filename or logical key. |
| `embeddable_type` / `embeddable_id` | The model identity (model sources only). |

```php
$hit->metadata['source_type']; // 'url'
$hit->metadata['source_ref'];  // 'https://example.com/articles/solar.html'
```

See **[Retrieval & search](/concepts/retrieval)** for how hits are scored and
**[Embedding & providers](/concepts/embedding)** for the embedders themselves.
