# Technical Notes — Intensive Adversary-Review Hardening

Branch: `hardening/intensive-adversary-review`. A holistic, cross-cutting security
and correctness pass over the whole package, beyond the per-cycle reviews. Four
independent adversarial auditors (crypto/KMS/tenancy, correctness/data-integrity,
input/injection/ReDoS, doc-vs-code accuracy) probed across module boundaries.

## Findings fixed

### Crypto / KMS / multi-tenancy (Batch A)
- **Crypto-shred completeness (GDPR).** Vectors hold *plaintext* content in their
  payload, so destroying the KEK doesn't erase them. Shred/purge now delete the
  tenant's/document's vectors from **every namespace** they were indexed into
  (tracked via `documents.indexed_namespace`), plus `EmbeddingRecord`,
  `IngestionRun`, `UsageRecord` rows and the tenant-tagged embedding cache.
- **KEK at-rest encryption.** `FileKeyStore` now encrypts KEK material with the
  `master_key` (AES-256-GCM) — the previously dead config — and writes atomically
  (temp+rename), honouring FR-SEC-03.
- **Confused-deputy.** `Ingestor::content()` refuses to decrypt a document outside
  its own tenant context.
- **Strict tenancy mode** (config `tenancy.strict`): reading the tenant before one
  is explicitly set throws, preventing silent fallback to the shared `default`.
- **Embedding cache** is now tenant-scoped (key + cache tag) so it can be evicted
  on shred and never shares entries across tenants. AEAD errors use one generic
  message (no structure oracle).

### Correctness / data integrity (Batch B)
- **EmbeddingRecord orphans.** Re-indexing now deletes the prior generation's
  embedding records (was unbounded growth + permanent reconcile inconsistency).
- **Cross-driver L2 scores.** InMemory and Qdrant now use one convention —
  `1/(1+distance)` (positive, higher-is-better) — so scores and thresholds behave
  identically across backends. The dead `distance_metric` config is wired in.
- **Rrf** uses an explicit positional rank counter (non-list inputs can't corrupt
  scores); **MMR** clamps λ to [0,1]; **dedup** keeps distinct whitespace hits;
  re-index is serialized with a per-document cache lock; `Usage::plus` rejects
  mixing currencies.

### Input hardening (Batch C)
- **DOCX DoS.** The quadratic `<w:p>.*?</w:p>` regex (a tiny crafted file burned
  minutes of CPU) is replaced with a linear `</w:p>` split plus size/count caps.
- **Email ReDoS.** Bounded-quantifier pattern → linear time; also adds IDN
  (Unicode host) support.
- **Phone over-redaction.** Dates, ISBNs and bare large numbers are no longer
  destroyed as phone numbers.
- **SSRF.** The guard resolves both A and AAAA records and the request **pins the
  validated IP** (curl `CURLOPT_RESOLVE`), defeating DNS-rebinding and IPv6
  dual-stack bypass.

### pgvector implemented (Batch D)
- The advertised-but-missing `pgvector` driver is now real: `DatabaseVectorStore`,
  a SQL-backed store (Postgres/Neon/MySQL/SQLite) doing a filtered brute-force
  scan — the small/on-prem tier (FR-VS-02). Shared `MetadataMatcher` keeps filter
  semantics identical to the in-memory store. Native ANN indexing remains a
  future optimisation.

### Documentation accuracy (Batch E)
- Fixed a broken `tenantKeyId:` example; corrected the "vectors encrypted at rest
  with a KMS key" and "irrecoverable even from backups" claims to match reality;
  fixed the `ClearCacheCommand` behaviour + docblock (now selective tag flush);
  added `wrapDataKey` to the contracts reference; documented the now-live
  `master_key`/`distance_metric`/`store` config and the cloud-KMS `extend()`
  registration; added the pgvector backend to the docs.
- **PHP version reconciled to 8.2+** (composer `^8.2`) — matching the spec
  (NFR-CP-01) — validated by running PHPStan level 8 with `phpVersion: 80200`.

## Quality gates (all green)

- **324 tests** passing.
- PHPStan **level 8 at the PHP 8.2 target**, no errors. Pint clean.

## Honest residual risks (documented, by design)
- The audit anchor lives in the same DB; a DDL/superuser attacker who drops the
  WORM triggers and rewrites entries + anchor consistently can defeat `verify()`.
  Full tamper-evidence needs the anchor replicated to an external append-only
  store.
- Pre-existing vector-store **backups** retain plaintext after a crypto-shred —
  outside the key-destruction guarantee; handled by backup retention policy.
- The GCM IV birthday bound applies to the KEK-wrap layer of very-high-volume
  un-rotated tenants (~2³² wraps); periodic key rotation resets it.
