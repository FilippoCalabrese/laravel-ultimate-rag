# Technical Notes — Cycle 6: Stable-release audit & hardening

Goal: audit the whole codebase for "declared but not implemented" features so
everything the docs claim is actually backed by working, tested code — to the bar
required for a first **stable** Composer release.

## Audit method

Four parallel read-only audits cross-checked documentation/config claims against
`src/` with file:line evidence: (1) retrieval/rerank/generation, (2) vector
stores/embedding/chunking/parsing/tokenization, (3) security/tenancy/quotas,
(4) console/events/config sweep. Confirmed correct (no change needed): 10
embedders, 8 parsers, 4 chunkers + parent-child + contextual headers, 3 vector
stores fully implementing the contract, BM25 keyword scorer (real), all 6 Artisan
commands, all 8 events dispatched, WORM audit triggers (sqlite/mysql/pgsql), all
3 quotas, 7 KMS ops, crypto-shredding, non-destructive key rotation, PII
mask/tokenize.

## Gaps found and fixed

- **Reranking claimed a cross-encoder but shipped only null/fake.** → Added real
  drivers `CohereReranker` + `JinaReranker` on a shared `HttpReranker` base,
  registered in `RerankerManager`, with config + env. (`RAG_RERANKER=cohere|jina`.)
- **Streaming was driver-only, unreachable via the public API.** → Added
  `RagGenerator::stream()` and `AskBuilder::stream()` so `Rag::ask(...)->stream()`
  yields tokens (retrieval runs first, then the LLM streams over SSE).
- **Qdrant `quantization` config was read but ignored.** → `QdrantStore` now emits
  `quantization_config` (scalar/binary) on collection creation; wired through the
  manager.
- **`RAG_QUEUE` (ingestion.queue) was dead config.** → `ProcessDocumentJob` and
  `SyncModelEmbeddingJob` now `onQueue(config('rag-engine.ingestion.queue'))`.
  Removed the unused `ingestion.sync` key.
- **Tenancy `isolation` accepted schema/database but only namespace was real.** →
  Docs corrected to state namespace-only; the service provider now **fails fast at
  boot** on any unimplemented isolation mode (no silent fallback — a data-isolation
  safety choice).
- **`MultiQueryTransformer` existed but wasn't reachable.** → Wired into the
  retriever via `SearchBuilder::expandQueries()` / `AskBuilder::expandQueries()`:
  the query is expanded into LLM-generated variants, each retrieved, and the
  results RRF-fused. Degrades to the single query under the null LLM.
- **Doc/config inaccuracies.** → `.env.example` `RAG_VECTOR_STORE=database` (no
  such driver) → `memory`; dedup documented as exact-duplicate (matches the
  implementation); honest "brute-force scan, native pgvector ANN is roadmap" note.

## Documentation

- New **[Vector stores](/concepts/vector-stores)** guide: which backend to use and
  step-by-step config, including exactly **where to set the Postgres connection
  for pgvector** (`config/database.php` + `RAG_PGVECTOR_CONNECTION`, and running
  migrations on a non-default connection).
- Updated retrieval (reranking providers, multi-query, dedup), generation
  (public streaming), multi-tenancy (honest isolation), configuration (subsystem
  map + pgvector pointer), README and `.env.example`.

## Quality gates (all green)

- **401 tests** (1611 assertions) — added reranker, streaming, multi-query,
  Qdrant quantization, queue-routing and isolation-guard tests.
- Coverage **93.2 %** (gate `--min=90`). PHPStan **level 8** clean. Pint clean.
- CI green across the matrix (Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13).
