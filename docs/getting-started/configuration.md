---
title: "Configuration"
description: "How the rag-engine config file is organised."
---

# Configuration

All configuration lives in `config/rag-engine.php`. The file is **`config:cache`
safe** — it contains only scalars, arrays and `env()` calls, never closures — so
it works with `php artisan config:cache` in production.

## Default drivers

The `defaults` block selects which named driver each subsystem uses:

```php
'defaults' => [
    'embedder'     => env('RAG_EMBEDDER', 'fake'),
    'vector_store' => env('RAG_VECTOR_STORE', 'memory'),
    'reranker'     => env('RAG_RERANKER', 'null'),
    'kms'          => env('RAG_KMS', 'local'),
    'tokenizer'    => env('RAG_TOKENIZER', 'approximate'),
    'llm'          => env('RAG_LLM', 'null'),
],
```

Each subsystem then has a block of **named connections**, every one declaring a
`driver`:

```php
'embedders' => [
    'fake'    => ['driver' => 'fake', 'dimensions' => 8],
    'mistral' => ['driver' => 'mistral', 'model' => 'mistral-embed', 'dimensions' => 1024, 'eu_resident' => true],
    'ollama'  => ['driver' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 768, 'eu_resident' => true],
],
```

This means you can have several connections sharing one driver type, each tuned
differently — and select between them at runtime by name.

## Security

```php
'security' => [
    'encryption_enabled'    => env('RAG_ENCRYPTION_ENABLED', true),
    'cipher'                => 'aes-256-gcm',
    'pii_redaction_enabled' => env('RAG_PII_REDACTION', true),  // ON by default
    'pii_strategy'          => env('RAG_PII_STRATEGY', 'mask'),
],
```

## Multi-tenancy

```php
'tenancy' => [
    'isolation'      => env('RAG_TENANCY_ISOLATION', 'namespace'), // namespace|schema|database
    'default_tenant' => env('RAG_DEFAULT_TENANT', 'default'),
    'quotas'         => [
        'max_documents'        => null, // null = unlimited
        'max_corpus_bytes'     => null,
        'max_embedding_tokens' => null,
    ],
],
```

::: callout warning "EU-residency"
The default embedding and KMS drivers are EU-resident or self-hostable.
Extra-EU providers (OpenAI, Cohere, Voyage) are available but only ever used on
an explicit, documented choice by the consumer.
:::

See **[Security & BYOK](/concepts/security)** for the encryption model.
