---
title: "Installation"
description: "Install the RAG Engine package into a Laravel application."
---

# Installation

## Requirements

- PHP **8.2+**
- Laravel **11, 12 or 13**

## Install via Composer

```bash
composer require sellinnate/rag-engine
```

The package auto-registers its service provider and the `Rag` facade through
Laravel package discovery.

## Publish configuration and migrations

```bash
php artisan vendor:publish --tag="rag-engine-config"
php artisan vendor:publish --tag="rag-engine-migrations"
php artisan migrate
```

This creates the core tables: documents, chunks, embeddings, data keys,
ingestion runs, usage records and the immutable audit log. The audit table is
protected by database-level WORM triggers so entries can never be updated or
deleted.

## Verify the install

```php
use Sellinnate\RagEngine\Facades\Rag;

Rag::tenant()->id();            // 'default'
Rag::embedder()->dimensions();  // 8  (the deterministic 'fake' embedder)
Rag::vectorStore()->name();     // 'memory'
```

::: callout info "Sensible, swappable defaults"
Out of the box the package uses deterministic, zero-network drivers (fake
embedder, in-memory vector store, local KMS) so your test suite runs offline.
Switch to production drivers — Mistral/Ollama embeddings, Qdrant/pgvector,
cloud KMS — entirely through configuration, without touching application code.
:::

Next: **[Configuration](/getting-started/configuration)**.
