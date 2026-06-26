# Technical Notes — Cycle 4: Orchestration, Tenancy, Audit, Security, DX & Generation

Branch: `feature/phase-4-orchestration`. The final cycle — completes the enterprise surface.

## What was built

- **Orchestration (FR-OR):** `IngestionPipeline` (state machine: pending→parsing→chunking→embedding→indexed→failed), `ProcessDocumentJob` (queued, batchable, retry+backoff, dead-letter via `failed()`).
- **Tenancy (FR-MT-04):** `TenantQuota` (document/corpus/token quotas, enforced in the ingest transaction).
- **Audit (NFR-CO-03):** `AuditLogger` — hash-chained, sequence-numbered, anchored append-only log with `verify()`.
- **Security (FR-SEC-04/05):** `CryptoShredder` (tenant/document erasure incl. vectors), `KeyRotationService` (transactional DEK re-wrap), shredded-tenant registry, `LocalKms::wrapDataKey`.
- **Generation (FR-GE):** `RagGenerator`, `ContextAssembler` (cited context), `AskBuilder`, `FakeLlm`. Isolated — null LLM yields empty answer.
- **Recovery (NFR-DR-02):** `Reconciler` (chunks↔embeddings consistency).
- **DX (FR-DX-04/05):** Artisan commands (`rag:status|stats|rotate-keys|purge|reconcile|clear-cache`), `Searchable` trait.
- **Query (FR-QT-01):** `MultiQueryTransformer`.

## Adversarial review — findings & resolutions

The reviewer proved two GDPR-critical failures. All MUST-FIX resolved:

- **C2 (CRITICAL) — crypto-shred left plaintext vectors in the store.** The vector payload stores plaintext content; destroying the KEK only made the *encrypted DB copy* unrecoverable. → `CryptoShredder::shredTenant` and `Ingestor::purge` now `deleteByFilter`/`delete` the tenant/document vectors from the store. Test proves no vector survives a shred.
- **C1 (CRITICAL) — audit log truncatable.** The hash chain linked only backward, so deleting trailing (or all) entries was undetectable. → Added a per-tenant **`AuditAnchor`** (seq high-water mark + head hash) and a `seq` in each hashed entry; `verify()` now checks count==anchor.seq, contiguous seq, and last hash==anchor head. Tests prove truncation and wholesale deletion are detected.
- **H1 — shred left derived tenant data.** → `UsageRecord`s are deleted in the shred transaction.
- **H3 — `Searchable` indexed every attribute** (password/token leak). → Defaults to a `$ragSearchable` allowlist / `$fillable`, never all attributes. Test proves secrets aren't indexed.
- **H2 — quota TOCTOU.** → Quota is enforced inside the write transaction, narrowing the race.
- **M1 — key rotation non-atomic + no audit on partial failure.** → Re-wrap runs in a transaction, tolerates corrupt DEKs (collected into a failures report, never aborts), and audits in a `finally`.
- **M2 — prompt-injection surface.** → The generation prompt fences retrieved context in `<context>` and instructs the model to treat it as untrusted data, not instructions.

## Quality gates (all green)

- **301 tests** passing (1311 assertions).
- Coverage **94.0 %** (gate `--min=90`).
- PHPStan **level 8**, no errors. Pint clean.

## Status: feature-complete across Phases 0–4

Every Must requirement across FR-IN/PA/PP/CH/EM/VS/RT/RR/OR/MT/DX/EV/SEC and the NFRs is implemented and tested. Remaining optional/Could items (HyDE/step-back, online re-embedding, native pgvector SQL, OCR engine, legacy/email parsers, Weaviate/Milvus drivers) are isolated driver additions enabled by the contract-first architecture.
