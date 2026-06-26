---
title: "RAG Engine for Laravel"
description: "Enterprise Retrieval-Augmented Generation infrastructure for Laravel — semantic search and AI answers over your own content."
---

# RAG Engine for Laravel

**RAG Engine** lets your Laravel app **search and answer questions over your own
content** — documents, database records, URLs — by *meaning*, not just keywords.
It handles the entire hard part of Retrieval-Augmented Generation (RAG): reading
your sources, splitting them, turning them into searchable vectors, and finding
the most relevant passages for any query. Writing a final answer with an LLM is
an optional layer on top.

::: callout tip "Brand new to this? Start here"
If words like *embedding*, *vector* or *chunk* are unfamiliar, read
**[What is RAG?](/getting-started/what-is-rag)** first (≈5 min, assumes zero
background). Then follow the **[Quickstart](/getting-started/quickstart)** to
build a working search feature end to end.
:::

## What you can build with it

- **Semantic search** — a search box that understands "how do I get my money
  back?" matches a page titled "Refund policy", even with no shared keywords.
- **AI Q&A / chatbots** — answers written by an LLM, grounded in *your* content,
  with citations back to the source.
- **"Ask your docs/tickets/wiki"** features inside an existing app.
- **Recommendation and similarity** — "find records like this one".

You can use just the search half (no LLM, no AI bill) or add generation later —
the same code, one config switch.

## Why a package instead of rolling your own

Doing RAG well means solving a dozen unglamorous problems: parsing many file
formats safely, splitting text without breaking meaning, batching embedding
calls, storing and querying vectors, filtering by metadata, isolating tenants,
encrypting sensitive content, redacting personal data, tracking cost, retrying
failed jobs. This package solves them once, behind a clean API, so your team
builds features instead of plumbing.

::: callout info "Five guiding principles"
1. **Domain-agnostic** — works with any content; the core knows only generic
   *documents, chunks, queries, results*.
2. **Contract-first** — every replaceable part (embedder, vector store, LLM…)
   sits behind a PHP interface, so you swap implementations without touching your
   code.
3. **Async-first** — slow indexing runs on a queue; search stays fast and
   synchronous.
4. **Multi-tenant & secure by design** — per-tenant data isolation and
   Bring-Your-Own-Key encryption are built in, not add-ons.
5. **EU-resident by default** — content and embeddings stay in the EU unless you
   explicitly opt into a non-EU provider.
:::

## What's in the box

- **Multi-format ingestion** — upload, raw text, URL, Eloquent records and cloud
  storage; safely parses Markdown, HTML, XML, CSV, JSON, DOCX and PDF.
- **Pluggable everything** — parsing, chunking, embedding, vector store,
  reranking and LLM are all swappable drivers behind contracts.
- **Powerful retrieval** — metadata filters, hybrid (semantic + keyword) search,
  result diversification (MMR), reranking, relevance thresholds and small-to-big
  context expansion.
- **Security built in** — envelope encryption, a Key-Management abstraction,
  crypto-shredding for "right to erasure", and PII redaction on by default.
- **Multi-tenancy** — automatic, fail-closed per-tenant scoping of every query.
- **Operations from day one** — an immutable audit log, cost tracking, lifecycle
  events and Artisan commands.

## A first taste

```php
use Sellinnate\RagEngine\Facades\Rag;

// Index a piece of content...
$document = Rag::ingest(Rag::source()->text('Refunds are issued within 14 days.'));
Rag::process($document);

// ...then search it by meaning.
$hits = Rag::search('how long until I get my money back?')->topK(3)->get();

$hits[0]->content;   // "Refunds are issued within 14 days."
$hits[0]->score;     // relevance score
```

## Where to go next

1. **[What is RAG?](/getting-started/what-is-rag)** — concepts and a full glossary.
2. **[Installation](/getting-started/installation)** — add the package to your app.
3. **[Quickstart](/getting-started/quickstart)** — a complete worked example.
4. **[Architecture](/concepts/architecture)** — how the pieces fit together.

::: callout info "Project status"
The engine is feature-complete and fully tested across ingestion, parsing,
preprocessing, chunking, embedding, vector storage, retrieval, reranking,
generation, orchestration, multi-tenancy and security. Drivers ship for the
common providers; new ones are isolated additions thanks to the contract-first
design.
:::
