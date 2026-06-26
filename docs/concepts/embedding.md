---
title: "Embedding"
description: "EU-resident and self-hosted embedding providers with caching and cost tracking."
---

# Embedding

Embedding turns chunk text into dense vectors. Providers are EU-resident or
self-hostable by default; every call is cached and its cost tracked.

## Providers

| Driver | Provider | Residency |
|---|---|---|
| `fake` | deterministic (tests/dev) | local |
| `mistral` | Mistral `mistral-embed` | EU cloud |
| `ollama` | Ollama (BGE/E5/Nomic) | self-hosted |

```php
use Sellinnate\RagEngine\Facades\Rag;

$response = Rag::embed(['first chunk', 'second chunk']);
$response->vectorAt(0);     // list<float>
$response->usage->tokens;   // tokens consumed
$response->usage->cost;     // monetary cost
```

## Caching

Repeated text is never re-embedded (FR-EM-05). The cache key is
`provider + model + dimensions + text`, so providers never poison each other's
cache, and cache hits cost nothing.

## Retry & resilience

HTTP providers are wrapped with exponential-backoff retry (FR-EM-06). Transient
failures (429, 5xx, connection errors) are retried; non-retryable errors (401
auth, 400 bad request, malformed/partial responses) fail fast.

::: callout info "Vector/chunk alignment is guaranteed"
If a provider ever returns fewer vectors than inputs, the engine raises an error
rather than silently mis-aligning embeddings to chunks — protecting the index
from corruption.
:::

## Cost tracking

Every embedding operation records tokens and cost per tenant, aggregatable per
period for billing (FR-EM-07, FR-MT-05):

```php
use Sellinnate\RagEngine\Observability\UsageRecorder;

$recorder = app(UsageRecorder::class);
$recorder->totalCost('tenant-1', '2026-06');
$recorder->totalTokens('tenant-1');
```
