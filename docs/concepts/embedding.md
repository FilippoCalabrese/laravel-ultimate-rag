---
title: "Embedding & providers"
description: "EU-resident, self-hosted and global embedding providers — OpenAI, Azure, Mistral, Jina, Voyage, Cohere, Gemini, Hugging Face, Ollama."
---

# Embedding & providers

Embedding turns chunk text into dense vectors. The engine ships **ten** drivers
behind one contract, every call is cached and its cost tracked, and you switch
provider with a single config line — no code changes.

```php
use Sellinnate\RagEngine\Facades\Rag;

$response = Rag::embed(['first chunk', 'second chunk']);
$response->vectorAt(0);     // list<float>
$response->usage->tokens;   // tokens consumed
$response->usage->cost;     // monetary cost
```

## Where do API keys go? {#credentials}

::: callout tip "Short answer: in your `.env`, never in code"
Each provider in `config/rag-engine.php` reads its key from an environment
variable via `env(...)`. You only set the variable in `.env` — the config file
stays committed and `config:cache`-safe.
:::

1. Publish the config (once): `php artisan vendor:publish --tag="rag-engine-config"`.
2. Add the keys for the providers you use to `.env`:

```dotenv
# Pick the provider(s) you need — set only their keys.
RAG_EMBEDDER=openai                 # which provider is the default

RAG_OPENAI_API_KEY=sk-...           # OpenAI
RAG_MISTRAL_API_KEY=...             # Mistral (EU)
RAG_JINA_API_KEY=jina_...           # Jina (EU)
RAG_VOYAGE_API_KEY=pa-...           # Voyage
RAG_COHERE_API_KEY=...              # Cohere
RAG_GEMINI_API_KEY=...              # Google Gemini
RAG_HF_API_KEY=hf_...               # Hugging Face

# Azure OpenAI (EU-resident when the resource is in an EU region)
RAG_AZURE_API_KEY=...
RAG_AZURE_ENDPOINT=https://<resource>.openai.azure.com
RAG_AZURE_DEPLOYMENT=<your-embedding-deployment>
RAG_AZURE_API_VERSION=2024-02-01

# Ollama — self-hosted, no key (just a URL)
RAG_OLLAMA_BASE_URL=http://localhost:11434
```

A ready-to-copy list of every variable lives in **`.env.example`** at the repo
root.

The key never goes in `config/rag-engine.php` directly — that file is committed
and cached. Provider-specific extras (Azure `deployment`, Cohere/Voyage
`input_type`, Jina `task`, OpenAI `organization`) go under that provider's
`options` array in the config (also via env).

## Providers

| Driver | Provider | Residency | Default model | Dims | Key env var |
|---|---|---|---|---|---|
| `openai` | OpenAI | global (opt-in) | `text-embedding-3-small` | 1536 | `RAG_OPENAI_API_KEY` |
| `azure-openai` | Azure OpenAI | **EU** (EU region) | `text-embedding-3-small` | 1536 | `RAG_AZURE_API_KEY` |
| `mistral` | Mistral | **EU** | `mistral-embed` | 1024 | `RAG_MISTRAL_API_KEY` |
| `jina` | Jina AI | **EU** | `jina-embeddings-v3` | 1024 | `RAG_JINA_API_KEY` |
| `voyage` | Voyage AI | global (opt-in) | `voyage-3` | 1024 | `RAG_VOYAGE_API_KEY` |
| `cohere` | Cohere | global (opt-in) | `embed-multilingual-v3.0` | 1024 | `RAG_COHERE_API_KEY` |
| `gemini` | Google Gemini | global (opt-in) | `text-embedding-004` | 768 | `RAG_GEMINI_API_KEY` |
| `huggingface` | Hugging Face | global / self-host | `BAAI/bge-small-en-v1.5` | 384 | `RAG_HF_API_KEY` |
| `ollama` | Ollama (BGE/E5/Nomic) | **self-hosted** | `nomic-embed-text` | 768 | — (local) |
| `fake` | deterministic | local | `fake-embed-v1` | 8 | — (tests/dev) |

::: callout warning "EU-resident by default"
Mistral, Jina, Azure-OpenAI-in-EU and Ollama keep data in the EU (principle 5).
OpenAI, Voyage, Cohere and Gemini are extra-EU — use them only on an explicit,
documented choice.
:::

## Choosing the default provider

Set `RAG_EMBEDDER` (or `rag-engine.defaults.embedder`):

```dotenv
RAG_EMBEDDER=mistral
```

Or pick one per call / per index:

```php
Rag::embed($texts, 'voyage');
Rag::index($document, $chunks, ['embedder' => 'openai']);
```

## Per-provider notes

- **OpenAI / Azure / Jina** — the v3 models accept a `dimensions` parameter
  (Matryoshka). Set the `dimensions` config value to the size you want; the
  engine sends it automatically (and validates that returned vectors match).
- **Voyage / Cohere / Jina** — set an `input_type` / `task` so documents and
  queries are embedded with the right asymmetry (better retrieval). Example for
  Cohere: ingestion uses `search_document`, queries use `search_query`.
- **Gemini** — uses Google's batch endpoint and the `x-goog-api-key` header.
- **Hugging Face** — targets the feature-extraction pipeline (any
  sentence-transformers model: BGE, E5, GTE, Nomic…); or point `base_url` at your
  own TEI/self-hosted endpoint.
- **Ollama** — fully local, no key; just run `ollama serve` and pull a model.

## Caching, retries & cost

- Repeated text is never re-embedded (FR-EM-05). The cache key is
  `tenant + provider + model + dimensions + text`, so providers and tenants never
  share entries.
- HTTP providers retry transient failures (429/5xx) with exponential backoff and
  fail fast on non-retryable errors (401/400). Toggle per provider with
  `cache` / `retries` config flags.
- Every call records tokens + cost per tenant (FR-EM-07); set `cost_per_1k` per
  provider to price it.

::: callout info "Vector/chunk alignment is guaranteed"
If a provider returns fewer vectors than inputs, the engine raises an error
rather than silently mis-aligning embeddings to chunks. OpenAI-style responses
are also re-ordered by their `index` field for safety.
:::

## Embedding Eloquent models

Beyond files and URLs, any Eloquent model can be embedded via a contract —
recursively composing related models, staying in sync as records change, and
tracing every vector back to its model. See
**[Embedding Eloquent models](/concepts/eloquent-models)**.

## A custom provider

Implement `Embedder` (or extend `HttpEmbedder` / `OpenAiCompatibleEmbedder`) and
register it:

```php
use Sellinnate\RagEngine\Managers\EmbedderManager;

app(EmbedderManager::class)->extend('acme', fn (array $config) => new AcmeEmbedder(...));
```

```php
// config/rag-engine.php
'embedders' => [
    'acme' => ['driver' => 'acme', 'api_key' => env('ACME_KEY'), 'dimensions' => 1024],
],
```
