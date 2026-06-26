---
title: "RAG Engine for Laravel"
description: "Enterprise Retrieval-Augmented Generation infrastructure for Laravel."
---

# RAG Engine for Laravel

**RAG Engine** is enterprise infrastructure — not a feature — for building
Retrieval-Augmented Generation into Laravel applications. It owns the whole
pipeline from *ingestion* to *retrieval*, while generation stays an optional,
decoupled layer. Vertical packages, internal agents and search modules build
domain features on top without re-implementing ingestion, chunking, embedding
or retrieval.

::: callout tip "Five guiding principles"
1. **Domain-agnostic** — generic primitives (documents, chunks, queries, results).
2. **Contract-first** — every replaceable component sits behind a stable interface.
3. **Async-first** — ingestion on a queue, retrieval synchronous and low-latency.
4. **Multi-tenant & secure by design** — per-tenant isolation and BYOK encryption are first-class.
5. **EU-resident by default** — data and embeddings stay in the EU unless explicitly overridden.
:::

## What's in the box

- **Multi-format ingestion** from upload, raw text, URL, Eloquent records and cloud storage.
- **Pluggable parsing, chunking, embedding, vector store, reranking** — all behind contracts.
- **Vector search** with metadata filters, hybrid (RRF), MMR, thresholds and reranking.
- **BYOK security**: envelope encryption, KMS abstraction and crypto-shredding.
- **Multi-tenancy** with namespace-per-tenant isolation and automatic query scoping.
- **Immutable audit log**, cost tracking and observability hooks from day one.

## Status

This documentation tracks the package as it is built, phase by phase. The
foundation layer (contracts, managers, models, events, envelope encryption with
a local KMS, deterministic test drivers, the service provider and facade) is in
place and fully tested.

::: card "Quick taste"
```php
use Sellinnate\RagEngine\Facades\Rag;

// Envelope-encrypt content under a tenant key (BYOK).
$payload = Rag::encrypter()->encrypt('confidential text', tenantKeyId: 'tenant-42');

// ...later, decrypt — unless the key was crypto-shredded.
$plain = Rag::encrypter()->decrypt($payload);
```
:::

Continue with **[Installation](/getting-started/installation)**.
