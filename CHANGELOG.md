# Changelog

All notable changes to `:package_name` will be documented in this file.

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
