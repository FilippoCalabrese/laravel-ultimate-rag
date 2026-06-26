---
title: "Orchestration & jobs"
description: "Async ingestion pipeline, state tracking, batches and dead-letters."
---

# Orchestration

Ingestion runs as an asynchronous, state-tracked pipeline:

```
pending → parsing → chunking → embedding → indexed
                                        ↘ failed
```

## Processing a document

```php
use Sellinnate\RagEngine\Facades\Rag;

$document = Rag::ingest(Rag::source()->file('handbook.pdf'));
Rag::process($document);            // synchronous pipeline
```

Or queue it (batchable across a whole corpus):

```php
use Sellinnate\RagEngine\Pipeline\ProcessDocumentJob;
use Illuminate\Support\Facades\Bus;

Bus::batch(
    $documents->map(fn ($d) => new ProcessDocumentJob($d->id, $d->tenant_id))
)->dispatch();
```

Jobs retry with backoff; a terminal failure marks the document `failed` and
emits `IngestionFailed` (dead-letter, FR-OR-05).

## Lifecycle events

`DocumentIngested`, `DocumentChunked`, `ChunksEmbedded`, `DocumentIndexed`,
`SearchPerformed`, `IngestionFailed`, `KeyRotated`, `DataShredded` — hook into
any of them.

## Artisan commands

| Command | Purpose |
|---|---|
| `rag:status` | document counts by pipeline state |
| `rag:stats {tenant}` | token/cost usage + quota consumption |
| `rag:reconcile {tenant}` | chunks↔vectors consistency report |
| `rag:rotate-keys {tenant}` | rotate KEK + re-wrap DEKs |
| `rag:purge {tenant}` | crypto-shred a tenant |
| `rag:clear-cache` | clear cached embeddings |

## Quotas

Per-tenant document, corpus-size and embedding-token quotas (FR-MT-04) are
enforced at ingestion and throw `QuotaExceededException` when breached.
