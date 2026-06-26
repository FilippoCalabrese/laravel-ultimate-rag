---
title: "Quickstart (build a Q&A feature)"
description: "A complete, runnable walkthrough: from an empty Laravel app to a working ingest-and-search feature."
---

# Quickstart: build a Q&A feature

By the end of this page you'll have a working "ask our handbook" feature: you
ingest some documents, then search them by meaning, and (optionally) get an
LLM-written answer with citations. Every step is copy-paste runnable.

New to the concepts (chunk, embedding, vector…)? Skim
**[What is RAG?](/getting-started/what-is-rag)** first — five minutes that make
everything below click.

::: callout info "What you need"
A Laravel 11/12/13 app with the package installed (see
**[Installation](/getting-started/installation)**). That's it — the defaults run
offline with no API keys, so you can follow along immediately.
:::

## Step 0 — Use a real embedder for real search

Out of the box the package uses the **`fake` embedder**: it's deterministic and
needs no network, which is perfect for automated tests — but it does **not**
understand meaning, so semantic search results will look random. For a real
feature, point the package at a real embedding model.

The easiest zero-cost, EU-friendly option is **[Ollama](https://ollama.com)**
running locally:

```bash
# Install Ollama, then pull a small embedding model:
ollama pull nomic-embed-text
```

```dotenv
# .env
RAG_EMBEDDER=ollama
RAG_OLLAMA_BASE_URL=http://localhost:11434
```

Prefer a hosted provider? Set `RAG_EMBEDDER=openai` and `RAG_OPENAI_API_KEY=…`
instead. See **[Embedding & providers](/concepts/embedding)** for all options and
exactly where keys go.

::: callout tip "Rule of thumb"
`fake` embedder → tests only. Any real search feature → a real embedder
(Ollama, Mistral, OpenAI…). The rest of your code stays identical; only `.env`
changes.
:::

## Step 1 — Ingest some content

"Ingesting" registers a source as a `Document`. It is stored (encrypted), but not
yet searchable — that happens in Step 2.

```php
use Sellinnate\RagEngine\Facades\Rag;

$handbook = [
    'Refunds are issued within 14 business days of an approved request.',
    'Employees accrue 25 days of paid leave per calendar year.',
    'Remote work is allowed up to three days per week with manager approval.',
];

foreach ($handbook as $i => $text) {
    $document = Rag::ingest(
        Rag::source()->text($text, ['document_key' => "handbook-{$i}", 'tag' => 'handbook'])
    );

    // Step 2 (below) — run the pipeline so the document becomes searchable.
    Rag::process($document);
}
```

`document_key` is a stable logical name; re-ingesting changed content under the
same key creates a new **version** and supersedes the old one (see
**[Ingesting content](/guides/ingestion)**).

## Step 2 — Process (make it searchable)

`Rag::process()` runs the full pipeline on a document: **parse → clean & redact
PII → chunk → embed → store**. We called it inline above for simplicity.

In production you'll want this on a queue so web requests stay fast:

```php
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;

$document = Rag::ingest(Rag::source()->text($text, ['document_key' => 'handbook-x']));
ProcessDocumentJob::dispatch($document->id, $document->tenant_id);   // runs on a worker
```

See **[Orchestration & jobs](/concepts/orchestration)** for batching a whole
corpus and tracking progress.

## Step 3 — Search

Now the interesting part. Ask a question in natural language; get back the most
relevant chunks — even when the wording differs from the source.

```php
$hits = Rag::search('how much holiday do I get?')
    ->topK(3)          // return the 3 best chunks
    ->get();

foreach ($hits as $hit) {
    echo round($hit->score, 3) . "  " . $hit->content . PHP_EOL;
}
```

Expected output (note: the query never said "leave", yet it matched):

```
0.811  Employees accrue 25 days of paid leave per calendar year.
0.402  Remote work is allowed up to three days per week with manager approval.
0.331  Refunds are issued within 14 business days of an approved request.
```

Each `$hit` carries full provenance so you can link back to the source:

```php
$hit->score;                      // relevance (higher = closer)
$hit->content;                    // the chunk text
$hit->documentId;                 // which Document it came from
$hit->metadata['source_type'];    // 'text' | 'url' | 'upload' | 'eloquent' | 'storage'
$hit->metadata['source_ref'];     // the URL / filename / key, when available
```

That's a complete semantic-search feature. Many apps stop here — no LLM needed.

### Tighten results with a threshold

Low-scoring hits are usually noise. Drop them with a relevance floor:

```php
$hits = Rag::search('how much holiday do I get?')
    ->topK(5)
    ->threshold(0.5)   // ignore anything below 0.5
    ->get();
```

## Step 4 (optional) — Generate an answer with an LLM

If you want a written answer instead of a list of chunks, configure an LLM driver
and use `ask()`. It retrieves *and* generates, with citations back to the source
chunks.

```php
$result = Rag::ask('how much holiday do I get?')
    ->topK(3)
    ->using('openai')   // an LLM driver name from rag-engine.llms config
    ->generate();

$result->answer;        // "Employees get 25 days of paid leave per year. [1]"
$result->citations;     // [['index' => 1, 'document_id' => '…', 'chunk_id' => '…']]
$result->sources;       // the SearchHits used to build the answer
```

With the default `null` LLM driver, `generate()` returns an empty answer plus the
sources — so the call is always safe even before you've configured an LLM. See
**[Generation](/concepts/generation)**.

## Putting it together: a controller

A realistic search endpoint:

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Sellinnate\RagEngine\Facades\Rag;

class HandbookSearchController
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate(['q' => ['required', 'string', 'max:300']]);

        $hits = Rag::search($validated['q'])
            ->topK(5)
            ->threshold(0.4)
            ->where('tag', 'handbook')   // only search handbook content
            ->get();

        return response()->json([
            'results' => array_map(fn ($hit) => [
                'text'   => $hit->content,
                'score'  => round($hit->score, 3),
                'source' => $hit->metadata['source_ref'] ?? null,
            ], $hits),
        ]);
    }
}
```

## Ingest at scale: an Artisan command

Bulk-ingest a folder of files, processing on the queue:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;

class ImportDocs extends Command
{
    protected $signature = 'docs:import {dir}';

    public function handle(): int
    {
        foreach (glob(rtrim($this->argument('dir'), '/') . '/*') as $path) {
            $document = Rag::ingest(
                Rag::source()->file($path, ['document_key' => basename($path)])
            );
            ProcessDocumentJob::dispatch($document->id, $document->tenant_id);
            $this->info("Queued: " . basename($path));
        }

        return self::SUCCESS;
    }
}
```

## Indexing Eloquent models instead of files

If the content you want to search already lives in your database (posts,
products, tickets…), you don't need to export files — make the model embeddable.
The package keeps the index in sync automatically as rows change. See the full
guide in **[Embedding Eloquent models](/concepts/eloquent-models)**:

```php
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;

class Article extends Model implements Embeddable
{
    use HasEmbeddings;   // auto-indexes on save, removes on delete

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->add('Body', $this->body);
    }
}
```

## Best practices

- **Switch off the `fake` embedder for any real feature** (Step 0). It's the #1
  cause of "search returns nonsense".
- **Process on a queue in production** so HTTP requests aren't blocked by
  embedding calls.
- **Use a stable `document_key`** for anything that can change, so re-imports
  version cleanly instead of piling up duplicates.
- **Filter with `where()`** (by tag, type, owner…) to keep results scoped and
  fast.
- **Set a `threshold()`** to cut low-relevance noise before showing results to
  users.
- **Keep the same embedder for indexing and querying.** Vectors from different
  models aren't comparable; if you switch models, re-index.

## Common pitfalls

::: callout warning "Read this if your search looks wrong"
- **Results look random** → you're still on the `fake` embedder. Set a real
  `RAG_EMBEDDER` (Step 0).
- **No results at all** → did you call `Rag::process()` (or dispatch
  `ProcessDocumentJob`) after ingesting? Ingesting alone does not index.
- **Changed the embedding model and everything broke** → re-index your corpus;
  old vectors have different dimensions/meaning and can't be mixed with new ones.
- **Searching returns another tenant's data** → it can't. If you expected
  cross-tenant results, that's by design (see
  **[Multi-tenancy](/concepts/multi-tenancy)**).
:::

## Where to go next

- **[Ingesting content](/guides/ingestion)** — every source type, dedup,
  versioning, deletion.
- **[Retrieval & search](/concepts/retrieval)** — hybrid, MMR, reranking,
  context expansion.
- **[Configuration](/getting-started/configuration)** — all the knobs.
