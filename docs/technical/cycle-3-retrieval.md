# Technical Notes — Cycle 3: Vector store, Retrieval & Reranking

Branch: `feature/phase-3-retrieval`.

## What was built

- **Indexer** (`Indexing/Indexer`): persists chunks (envelope-encrypted at rest), embeds children, upserts vectors with tenant/document/parent metadata. Re-index is failure-safe (see C1 below).
- **Retriever** (`Retrieval/Retriever`): the full pipeline — embed query → vector search (fail-closed tenant scope) → hybrid RRF → rerank → threshold → dedup → MMR/top-k → parent expansion → context budget → `SearchPerformed`.
- **SearchBuilder** (`Retrieval/SearchBuilder`): fluent API (FR-RT-08) producing a `SearchRequest`.
- Primitives: `Rrf` (FR-RT-03), `Mmr` (FR-RT-05), `KeywordScorer` (BM25, FR-RT-03), `Support\Vectors`.
- **QdrantStore** (`VectorStore/QdrantStore`): primary backend (FR-VS-01) over HTTP; filter DSL translation; namespace validation.
- `SearchHit` extended with the stored vector (for MMR).

## Key decisions

1. **Fail-closed tenant scoping** resolves the Cycle-0 C1 boundary: the Retriever ALWAYS scopes to `TenantContext::id()`. The store's `effectiveFilters` writes the tenant scope *after* user filters, so a user `tenant_id` filter can never widen scope.
2. **Chunk text** is envelope-encrypted in the DB (BYOK); plaintext lives only in the vector-store payload, which sits inside the tenant perimeter (FR-SEC-06). Parent expansion decrypts the parent chunk from the DB.
3. **Hybrid** scores the vector candidate pool with BM25 and RRF-fuses — a valid, deterministic approximation that catches exact-term matches without a separate keyword index.

## Adversarial review — findings & resolutions

All MUST-FIX items resolved:

- **C1 (CRITICAL) — re-indexing was not atomic.** The old purge ran *before* embedding, so a failed embed destroyed the prior index (orphan chunks). → Reordered: embed first → upsert NEW vectors (new ids) → swap chunk rows in one DB transaction → delete OLD vectors last. A failure at any step leaves the prior generation intact. Test simulates an embed failure and asserts prior chunks survive.
- **H1 (HIGH) — caller-supplied tenant could widen scope.** `SearchBuilder::forTenant()` overrode the context with no authorization. → Removed; scope is always the ambient `TenantContext`. Cross-tenant search uses the authorized `Rag::forTenant()` (sets+restores context). Test asserts a malicious `tenant_id` filter cannot leak another tenant.
- **H2 (HIGH) — silent dimension overwrite corrupted scoring on model change.** → `createNamespace` rejects a dimension change on a populated namespace; `search` rejects a wrong-dimension query vector. Tests cover both.
- **H3 (HIGH) — Qdrant namespace path injection.** → Namespace validated against `^[A-Za-z0-9_-]{1,64}$` before URL interpolation. Test asserts `../../collections/victim` is rejected.
- **M1 — context budget counted child tokens after parent expansion.** → Budget now counts the expanded `parent_content` actually consumed.
- **M2 — dedup/threshold after MMR under-filled results.** → Threshold + dedup now run on the candidate pool *before* the final top-k selection, so unique results backfill to topK. Test asserts deduped backfill.

## Quality gates (all green)

- **266 tests** passing (1232 assertions).
- Coverage **93.7 %** (gate `--min=90`).
- PHPStan **level 8**, no errors. Pint clean.

## Carried forward

- Cycle 4: async ingestion pipeline (`Bus::batch`: parse→preprocess→chunk→embed→index), dead-letter/backpressure, tenancy quotas, immutable audit hash chain, crypto-shred tenant registry (resolves Cycle-0 M1), key rotation, query transforms, optional generation, Artisan commands, reconciliation (NFR-DR), DX trait. pgvector driver (DB-backed) is still pending.
