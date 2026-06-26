# Technical Notes — Cycle 5: Eloquent model embedding (FR-DX-05)

Branch: `feature/model-embedding`. Adds first-class embedding of Eloquent models on top of the existing ingest → chunk → embed → index pipeline, plus end-to-end vector provenance.

## What was built

- **Contract (`Contracts\Embeddable`):** a single method, `toEmbeddable(): EmbeddableDefinition`, is the source of truth for *what* a model embeds. Identity/sync machinery lives in the trait, keeping the contract focused.
- **Definition (`Eloquent\EmbeddableDefinition`):** fluent builder — `add()`/`text()` (labelled/plain parts, blanks dropped), `include()`/`includeMany()` (related embeddables → recursion), `metadata()`, `documentKey()`, `options()`.
- **Compiler (`Eloquent\EmbeddableCompiler`):** renders an embeddable (and its relations) into one composed document. Bounded by `eloquent.max_depth`, cycle-guarded by a visited-key set, records `includedKeys` for provenance. Returns `CompiledEmbeddable` (content, metadata, includedKeys, options, documentKey, checksum).
- **Service (`Eloquent\ModelEmbedder`):**
  - `sync()` — compile → ingest (versioned by the model's `type:id` key) → process, only re-processing when content actually changed (checksum dedup), then **purge superseded generations** so a field change leaves no orphan vectors.
  - `forget()` / `forgetByIdentity()` — purge every document for the model's key.
  - `resolve(SearchHit|Document|array)` — morph-aware trace-back to the originating model; `null` for non-model sources.
- **Traits:** `Concerns\HasEmbeddings` (root models — identity + auto-sync on save/delete/restore + manual `syncEmbedding()`/`forgetEmbedding()`); `Concerns\TouchesEmbeddingParents` (child models — re-sync declared `embeddingParents()` when the child changes, without being a document itself).
- **Queue (`Pipeline\SyncModelEmbeddingJob`):** carries the model's morph identity (so a delete is processable after the row is gone), runs in the model's tenant context, syncs or forgets. Enabled by `eloquent.queue`.
- **Wiring:** `EmbeddableCompiler` + `ModelEmbedder` registered as singletons; `RagEngine::models()` / `Rag::models()` accessor; `eloquent` config block.

## Provenance — every chunk is traceable (follow-up requirement)

`Indexer::buildRecords` now writes a provenance block into **every** vector payload (not just models), so any hit traces back to its origin:

- `document_id` (always), `source_type` (`eloquent`/`url`/`upload`/`text`/`storage`), and `source_ref` (first of `url`/`filename`/`document_key`/`source_ref`/`key` in the document metadata).
- A generic `rag_vector_metadata` map on the document is merged in verbatim — `ModelEmbedder` uses it to stamp `embeddable_type`/`embeddable_id`/`embeddable_key`, so model trace-back needs **no second query**. System keys (tenant_id, document_id, chunk_id…) are written last and can never be shadowed by propagated metadata.

## Design decisions

- **Composition over linked sub-vectors.** A model's relations are inlined into the parent's composed document rather than stored as separate linked vectors. One root model = one document; a query matching related content still resolves to the parent. Simpler, cycle-safe, and keeps retrieval/trace-back single-hop.
- **No orphan vectors on change.** Re-ingestion supersedes the prior version (existing versioning) but does **not** clean its vectors; `ModelEmbedder` explicitly purges every other document sharing the model key after a successful sync. Proven by a test asserting the namespace holds exactly one vector after an edit.
- **Config evaluated at fire-time, not boot-time.** The traits always register model listeners and check `eloquent.auto_sync` when the event fires, so the switch is honoured at runtime (and per-test) instead of being frozen at first model boot.
- **Identity via morph map.** `getMorphClass()` for the type and `Relation::getMorphedModel()` on the way back, so morph-mapped aliases round-trip.

## Quality gates (all green)

- **377 tests** passing (1549 assertions) — incl. `Unit/Eloquent/EmbeddableCompilerTest` (compose, recursion, depth, cycle, blanks, checksum, definition API) and `Feature/Eloquent/ModelEmbeddingTest` + `Feature/Indexing/ProvenanceTest` (index/search, trace-back, recursive related, field-change-no-orphans, delete, auto-sync, child→parent re-sync, queue dispatch + handler, unknown-morph safety, non-model provenance).
- Coverage **93.5 %** (gate `--min=90`).
- PHPStan **level 8**, no errors. Pint clean.

## Public surface

```php
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Concerns\TouchesEmbeddingParents;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;

// Root model: implements Embeddable + uses HasEmbeddings.
// Child model: implements Embeddable + uses TouchesEmbeddingParents (declares embeddingParents()).

$post->syncEmbedding();              // manual (re)index
$post->forgetEmbedding();            // manual remove
Rag::models()->sync($post);          // service-level
Rag::models()->resolve($searchHit);  // vector → model
```

Config: `rag-engine.eloquent.{auto_sync,queue,max_depth,namespace}`.
