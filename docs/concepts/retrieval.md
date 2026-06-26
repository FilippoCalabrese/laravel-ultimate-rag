---
title: "Retrieval & search"
description: "Fluent semantic search with hybrid, MMR, reranking and context expansion."
---

# Retrieval & search

Retrieval is a fluent, driver-agnostic pipeline. Tenant scoping is mandatory and
fail-closed — a query is **always** scoped to the current tenant.

## The fluent builder

```php
use Sellinnate\RagEngine\Facades\Rag;

$hits = Rag::search('how does envelope encryption work?')
    ->topK(5)
    ->threshold(0.3)
    ->where('source', 'handbook')   // metadata filter (FR-RT-04)
    ->hybrid()                       // vector + BM25 with RRF (FR-RT-03)
    ->mmr(0.6)                       // diversify results (FR-RT-05)
    ->rerank()                       // cross-encoder rerank (FR-RR-01)
    ->expandParents()                // small-to-big context (FR-RT-07)
    ->contextBudget(2000)            // token-aware budget (FR-RR-04)
    ->get();

foreach ($hits as $hit) {
    $hit->score;                 // relevance
    $hit->content;               // chunk text
    $hit->documentId;            // provenance (FR-RT-06)
    $hit->metadata;              // tags, heading, parent_content, ...
}
```

## Indexing

Before searching, index a document's chunks:

```php
$document = Rag::ingest(Rag::source()->text($body));
$chunks   = Rag::chunk($parsed, ['parent_child' => true]);
Rag::index($document, $chunks);
```

Re-indexing a document **atomically replaces** its prior generation: the new
vectors are written before the old ones are removed, so a failure mid-way never
destroys a working index (FR-AF-05).

## Multi-tenant isolation

```php
// Authorized cross-tenant access sets the context (and restores it after):
$results = Rag::forTenant('tenant-42', fn () => Rag::search('q')->get());
```

::: callout warning "Scope cannot be widened from the query"
There is intentionally no `forTenant()` on the search builder. A `tenant_id`
filter on a query can never override the ambient tenant — the engine overwrites
it with the current tenant. Cross-tenant isolation is a tested invariant.
:::

## Backends

| Driver | Backend |
|---|---|
| `memory` | in-process (tests, dev) |
| `qdrant` | Qdrant (primary, EU self-hostable) |

Choose per query with `->store('qdrant')`, or set the default in config.
