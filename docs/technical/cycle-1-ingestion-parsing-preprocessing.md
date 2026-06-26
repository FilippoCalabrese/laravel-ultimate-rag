# Technical Notes — Cycle 1: Ingestion, Parsing, Preprocessing

Branch: `feature/phase-1-ingestion`. Builds on Cycle 0 foundations.

## What was built

**Preprocessing (FR-PP)**
- `PreprocessingStage` contract + `PreprocessingPipeline` (composable, config-ordered, FR-PP-04).
- `TextCleaner` (FR-PP-01): UTF-8 normalization, whitespace collapse, control/zero-width stripping.
- `PiiRedactor` (FR-PP-03, ON by default): email, credit card (Luhn), IBAN (mod-97), Italian CF, P.IVA, phone. `mask` and reversible `tokenize` strategies.
- `LanguageDetector` (FR-PA-11): stopword-frequency IT/DE/EN.

**Parsing (FR-PA)**
- `ParserManager` registry (FR-PA-13): last-registered wins, resolve by MIME.
- No-dependency parsers: PlainText, Markdown, Html (sanitized DOM), Xml (XXE-safe), Csv/Tsv (table-preserving), Json (path:value flatten), Docx (ZipArchive, zip-bomb capped).
- `PdfParser` backed by optional `smalot/pdfparser` (suggested dep; `isAvailable()` gate).

**Ingestion (FR-IN)**
- `IngestionSource` DTO + `SourceFactory` (text, file, storage disk, URL, Eloquent).
- `Ingestor`: dedup by content hash (FR-IN-06), provenance (FR-IN-07), versioning with atomic supersede (FR-IN-08), arbitrary metadata (FR-IN-09), envelope encryption, soft-delete + crypto-shredding purge (FR-IN-10).
- `SsrfGuard` for URL ingestion.

## Key decisions

1. **DOCX/OOXML without a dependency**: a DOCX is a ZIP of XML, parsed with the built-in `ZipArchive` + regex over `word/document.xml`. Avoids pulling phpoffice.
2. **PDF dependency is optional**: `smalot/pdfparser` is a dev/suggest dependency; the parser registers only when available, so search-only consumers stay lean.
3. **Per-document crypto-shredding**: the wrapped DEK lives inside the document row (`encrypted_content_ref`). Purging the row deletes the only copy of the wrapped DEK → content unrecoverable even with the tenant KEK alive.
4. **Version uniqueness**: `(tenant_id, content_hash, version)` is unique; `resolveVersion` bumps above any existing (incl. soft-deleted) row with the same hash so re-ingestion after soft-delete works.

## Adversarial review — findings & resolutions

A hostile reviewer audited this cycle. All MUST-FIX items were resolved:

- **C1 (CRITICAL) — PII redaction only touched `text`.** CSV rows, JSON trees, PDF/HTML/DOCX sections and document metadata leaked PII verbatim, defeating the ON-by-default guarantee. → `PiiRedactor::process` now redacts text, every section (content + nested metadata) and the full metadata tree recursively. Tests assert redaction reaches `sections[].metadata.rows` and `metadata.json.*`.
- **H1/H2 — IBAN (spaced/lowercase) and credit-card (`.`/NBSP separators) bypasses.** → Patterns broadened (`/i`, internal spaces; dot/NBSP/thin-space separators). A greedy `/i` IBAN match could swallow the next word and fail mod-97; added `longestValidPrefix()` trimming so the longest valid head is redacted and the trailing token preserved.
- **H3 — SSRF in `SourceFactory::url()`.** No internal-IP block. → Added `SsrfGuard`: rejects non-http(s) schemes and any host resolving to private/loopback/link-local/reserved (blocks 169.254.169.254, localhost, RFC1918, ::1, fc00::/7). Redirects disabled (a permitted host could 302 inward).
- **H4 — TOCTOU dedup→insert** threw an unhandled `QueryException` under concurrency. → The insert is wrapped; on `UniqueConstraintViolationException` the winner is re-queried and returned.
- **M1 — DOCX memory-amplification DoS.** 200 MB cap was above a typical `memory_limit`. → Default cap lowered to 20 MB.
- **M2 — XXE regex bypass via UTF-16.** ASCII regex couldn't see an interleaved-null DOCTYPE. → Added `libxml_set_external_entity_loader(null)` to disable external entities and a post-parse `$dom->doctype !== null` rejection (catches UTF-16). Regex kept as a fast pre-check.
- **L3 — misleading `DataShredded` keyId** (was the shared KEK id). → Now emits the document id with scope `document`.

Deferred / accepted: phone over-matching of ambiguous numeric runs is intentional safe-side over-redaction (LOW). True serialization of concurrent different-content same-key versioning is a backstop, not yet enforced with row locks.

## Quality gates (all green)

- **188 tests** passing (480 assertions).
- Coverage **93.3 %** (gate `--min=90`).
- PHPStan **level 8**, no errors. Pint clean.

## Carried forward

- Cycle 2: chunking (all strategies + parent-child + contextual headers) consumes `ParsedDocument`; embedding providers (Mistral/Ollama HTTP) with cache + cost tracking. The async ingestion *pipeline* (parse→preprocess→chunk→embed→index as `Bus::batch`) lands when chunking/embedding exist — Cycle 1 delivers the synchronous building blocks and the Document lifecycle.
- Cycle 3: fail-closed tenant scoping in retrieval (resolves Cycle 0 C1).
