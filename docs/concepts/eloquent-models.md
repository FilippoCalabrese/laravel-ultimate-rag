---
title: "Embedding Eloquent models"
description: "Make any Eloquent model embeddable via a contract — recursively, with automatic sync on change and full vector-to-model trace-back."
---

# Embedding Eloquent models

Index your domain models — not just files. A model declares **what** of itself
is embedded through one contract; the engine composes it (recursively, including
related models), keeps the index in sync as the model changes, and lets you walk
back from any retrieved vector to the originating model.

::: callout info "In plain words"
If the content you want to search already lives in your database (blog posts,
products, support tickets…), you don't need to export it to files first. You add
a trait and one method to the model, and it becomes searchable — and *stays*
searchable automatically as rows are created, edited and deleted. New to the
core ideas? Read **[What is RAG?](/getting-started/what-is-rag)** first.
:::

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
| `addFile(string $label, ?string $path, ?string $disk, ?string $mime)` | Embed the **text of an uploaded file** (PDF, DOCX…). See [file fields](#file-fields). |
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

## Embedding file fields (PDF, DOCX…) {#file-fields}

If a model has an **uploaded file** (a PDF contract, a DOCX report…), you can
fold its text straight into the model's embedding with `addFile()`. The engine
reads the file, parses it to text with the same parsers used for ingestion
(PDF, DOCX, HTML, CSV, JSON, XML, Markdown, text), and includes it under the
given label — so the file becomes searchable *as part of the model* and a hit
still resolves back to that model.

```php
class Contract extends Model implements Embeddable
{
    use HasEmbeddings;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            // A file stored on a Laravel disk (e.g. 's3'):
            ->addFile('Document', $this->document_path, 's3')
            // ...or a local absolute path (omit the disk):
            ->addFile('Appendix', $this->appendix_absolute_path);
    }
}
```

- **`$disk`** — a Laravel filesystem disk name (`config/filesystems.php`). Omit it
  to treat `$path` as a local absolute path.
- **`$mime`** — optional; detected from the file extension/content when omitted.
- A **null/blank `$path` is ignored**, so nullable upload columns are safe.

::: callout info "PDF support needs one extra package"
PDF parsing uses the optional `smalot/pdfparser` package — run
`composer require smalot/pdfparser` to enable it. Without it, PDFs are treated as
non-embeddable (see below). DOCX/HTML/CSV/JSON/XML/Markdown/text need nothing extra.
:::

### Non-embeddable files (zip, executables, images…)

Not every file can become text. A `.zip`, an executable, an image, a corrupt
file, a missing path, or one over the size limit **can't be embedded** — and the
engine never sends raw binary to an embedding provider. What happens is governed
by `rag-engine.eloquent.on_unparsable_file`:

| Policy | Behaviour |
|---|---|
| `skip` (default) | Logs a warning and **embeds the rest of the model** (other fields/relations). The bad file simply contributes nothing. |
| `fail` | Throws `Sellinnate\RagEngine\Exceptions\UnsupportedFileException`, so the sync fails loudly. |

```dotenv
# .env
RAG_ELOQUENT_ON_UNPARSABLE_FILE=skip      # skip | fail
RAG_ELOQUENT_MAX_FILE_BYTES=26214400      # largest file the engine will read (bytes)
```

```php
// Handle strict mode explicitly when you want to surface the error to the user:
use Sellinnate\RagEngine\Exceptions\UnsupportedFileException;

try {
    $contract->syncEmbedding();
} catch (UnsupportedFileException $e) {
    // e.g. "Cannot embed file — file [archive.zip] is not embeddable: no parser for type [application/zip] ..."
}
```

::: callout tip "Which to choose?"
Use **`skip`** (default) for user uploads where a non-text attachment shouldn't
block indexing the record. Use **`fail`** when a model *must* have an embeddable
document and you want to catch mistakes early.
:::

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

## Every chunk is traceable {#every-chunk-is-traceable}

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
