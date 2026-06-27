# Changelog

All notable changes to `:package_name` will be documented in this file.

## v1.1.1 — fix PHPStan CI (console input typing) - 2026-06-27

Patch release. No runtime or behaviour change.

### Fix

- The standalone **PHPStan** CI workflow (PHP 8.5 + latest larastan) failed on console commands casting `mixed` `argument()`/`option()` values to `string` — an unsound cast that older local larastan didn't flag (the run-tests matrix was always green; PHPStan had been red since v1.0.0).
- Added `Console\Concerns\NormalizesInput` (`stringArgument(): string`, `stringOption(): ?string`) and used it across all six Artisan commands. No more `mixed → string` casts.

Verified against the same larastan version CI installs: PHPStan level 8 clean. Suite 410 green; **both** run-tests and PHPStan CI green.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.1.0 — embeddable file fields (PDF/DOCX) + binary handling - 2026-06-27

### What's new

Embeddable Eloquent models can now embed **file fields** (an uploaded PDF, DOCX, …), and non-embeddable binaries are handled safely.

- **`EmbeddableDefinition::addFile($label, $path, $disk = null, $mime = null)`** — fold an uploaded file's text into the model's embedding. The engine reads the file (from a Laravel disk or a local path), parses it with the built-in parsers (PDF, DOCX, HTML, CSV, JSON, XML, Markdown, text), and the file becomes searchable **as part of the model** — a search hit still resolves back to the model.
- **Robust binary handling** — a `.zip`, executable, image, missing/empty/oversized or unparsable file can't be embedded, and raw binary is **never** sent to an embedding provider. Behaviour is governed by `rag-engine.eloquent.on_unparsable_file`:
  - `skip` (default) — log a warning and embed the rest of the model.
  - `fail` — throw `Sellinnate\RagEngine\Exceptions\UnsupportedFileException`.
  - Size guard via `rag-engine.eloquent.max_file_bytes`.
  

```php
public function toEmbeddable(): EmbeddableDefinition
{
    return EmbeddableDefinition::make()
        ->add('Title', $this->title)
        ->addFile('Document', $this->document_path, 's3'); // PDF/DOCX on a disk
}


```
> PDF support uses the optional `smalot/pdfparser` package (`composer require smalot/pdfparser`). Other formats need nothing extra.

### Compatibility

Fully backward compatible. PHP 8.2+ · Laravel 11/12/13.

### Quality

410 tests (incl. PDF on local path & Laravel disk, zip/exe skip & fail, missing/oversized file) + native-pgvector CI integration job, PHPStan level 8, Pint, coverage ≥90%, green across Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13.

📖 Docs: https://laravel-rag-engine.selli.io/concepts/eloquent-models#file-fields

🤖 Generated with [Claude Code](https://claude.com/claude-code)

## v1.0.0 — first stable release - 2026-06-27

First stable release of **RAG Engine for Laravel** — semantic search and AI answers over your own content, behind stable contracts.

### Highlights

- **Ingestion** from text, files, URLs (SSRF-guarded), cloud storage and Eloquent records; safe parsing of Markdown/HTML/XML/CSV/JSON/DOCX/PDF.
- **Preprocessing & PII redaction** (mask/tokenize) on by default.
- **Chunking**: recursive/sentence/markdown/fixed, token-aware, parent-child, contextual headers.
- **Embedding — 10 providers**: OpenAI, Azure OpenAI, Mistral, Jina, Voyage, Cohere, Gemini, Hugging Face, Ollama, + deterministic `fake`.
- **Vector stores**: `memory`, portable `database` (SQL), **native `pgvector`** (vector column + HNSW + `<=>`), `qdrant` (incl. quantization).
- **Retrieval**: metadata filters, hybrid (BM25 + RRF), MMR, **cross-encoder reranking** (Cohere/Jina), thresholds, multi-query expansion, small-to-big parent expansion, token budgeting.
- **Generation**: Anthropic (Claude) + OpenAI-compatible LLMs, cited answers, **streaming** via `Rag::ask()->stream()`.
- **Embeddable Eloquent models** via a contract — recursive relation composition, auto-sync, vector→model trace-back.
- **Security**: BYOK envelope encryption, KMS abstraction, crypto-shredding, key rotation; tamper-evident WORM audit log.
- **Multi-tenancy**: fail-closed per-tenant scoping. **Operations**: cost tracking, lifecycle events, queued/batchable jobs, Artisan commands.

### Requirements

PHP 8.2+ · Laravel 11, 12 or 13.

### Install

```bash
composer require sellinnate/rag-engine



```
### Quality

401 tests + a native-pgvector CI integration job (real Postgres), PHPStan level 8, Pint, coverage ≥90%, green across Linux + Windows × PHP 8.3/8.4/8.5 × Laravel 12/13.

📖 Docs: https://laravel-rag-engine.selli.io

🤖 Generated with [Claude Code](https://claude.com/claude-code)
