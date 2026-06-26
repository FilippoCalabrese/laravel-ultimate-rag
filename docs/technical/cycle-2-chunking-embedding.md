# Technical Notes — Cycle 2: Chunking & Embedding

Branch: `feature/phase-2-chunking-embedding`.

## What was built

**Chunking (FR-CH)**
- `AbstractChunker` base: token counting (FR-CH-06) + metadata propagation (FR-CH-09, allow/deny list).
- Strategies: `FixedSizeChunker` (char + token unit), `RecursiveCharacterChunker` (separator hierarchy), `SentenceChunker`, `MarkdownChunker` (heading-aware, sub-splits oversized sections).
- `ParentChildChunker` (FR-CH-07): parents + children in one list, children reference parent by index — no text duplication.
- `ContextualHeaderEnricher` (FR-CH-08): document/section context prepended into `embeddableText()`.
- `ChunkerManager` (FR-CH-10 driver) + `ChunkingService` orchestrator.

**Embedding (FR-EM)**
- `HttpEmbedder` base + `MistralEmbedder` (EU, FR-EM-01) + `OllamaEmbedder` (self-hosted, FR-EM-02).
- `CachingEmbedder` (FR-EM-05), `RetryingEmbedder` (FR-EM-06) decorators, composed by `EmbedderManager`.
- `UsageRecorder` (FR-EM-07, FR-MT-05): per-tenant token/cost record + aggregation.
- `EmbeddingService`: batched embedding (FR-EM-04), usage tracking, model/dims on response (FR-EM-08).

## Adversarial review — findings & resolutions

All MUST-FIX items resolved:

- **C2 (CRITICAL) — no `#vectors == #inputs` check.** A partial provider response silently zipped embeddings to the wrong chunks (permanent index corruption). → `HttpEmbedder` now throws if the vector count ≠ input count. Test asserts the mismatch throws.
- **C3 (CRITICAL) — cache key omitted provider identity.** Two providers sharing a model name + dims poisoned each other's cache. → The cache key now includes a provider identity (the connection name). Test proves provider isolation.
- **C1 (CRITICAL) — RecursiveCharacterChunker offsets were garbage** (0,1,2,3…). Reverse-`mb_strpos` on space-rejoined text always failed and fell back to a +1 cursor. → Offsets are now tracked structurally through `atomize`/`merge` (each atomic piece keeps its true source position; chunk offset = first piece). Invariant tests assert offsets are monotonic, in-bounds and anchor the first word; char-mode offsets equal the exact source slice.
- **H1 — CachingEmbedder double-charged duplicate texts in a batch.** → Misses are de-duplicated by text before hitting the provider, then fanned back to all positions. Test asserts a duplicate is embedded once.
- **H2 — RetryingEmbedder retried non-retryable errors** (401/400/malformed). → New `EmbeddingException` carries a `retryable` flag (429/5xx/connection = retryable; 4xx/partial = not). RetryingEmbedder skips retry for non-retryable. Tests assert a 401 is tried once and a 503 is retried.
- **H3 — ParentChildChunker duplicated full parent text into every child** (8.3× memory). → Parents are emitted once; children reference `parent_index`. No `parent_content` duplication.
- **M1 — token chunking was O(words × window).** → Per-word tokens are pre-counted once and accumulated (O(n)); a perf test bounds an 8k-word doc under 500 ms.
- **M3 — recursive overlap could push a chunk over `size`.** → Piece-level overlap with size-respecting windows; test asserts no chunk exceeds the budget.
- **M2 — `offset` units inconsistent across strategies.** → Each chunk records `offset_unit` (`char`/`word`/`sentence`).

## Quality gates (all green)

- **232 tests** passing (1144 assertions).
- Coverage **93.3 %** (gate `--min=90`).
- PHPStan **level 8**, no errors. Pint clean.

## Carried forward

- Cycle 3: persistence of chunks (incl. parent_chunk_id from ParentChildChunker), indexing into the vector store, retrieval with fail-closed tenant scoping, hybrid+RRF, MMR, reranking, parent-context expansion (FR-RT-07 consumes `parent_index`).
- The async ingestion pipeline (parse→preprocess→chunk→embed→index as `Bus::batch`) is assembled in Cycle 4 once indexing exists.
