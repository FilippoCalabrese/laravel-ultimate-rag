---
title: "Generation (RAG)"
description: "Optional answer generation with cited sources."
---

# Generation

The generation layer is **optional and isolated** (FR-GE-05): search-only
consumers carry no LLM dependency. When configured, ask questions over the
corpus and get an answer with cited sources.

```php
use Sellinnate\RagEngine\Facades\Rag;

$result = Rag::ask('What are the GDPR obligations for data erasure?')
    ->topK(5)
    ->hybrid()
    ->expandParents()
    ->using('mistral')      // LLM driver
    ->generate();

$result->answer;     // the generated answer
$result->citations;  // [{index, document_id, chunk_id}, ...]
$result->sources;    // the SearchHits used
```

## Cited context

Retrieved chunks are assembled into a numbered, token-budgeted context block,
and citations map each `[n]` marker back to its source document and chunk
(FR-GE-03).

::: callout warning "Prompt-injection hardening"
Retrieved document text is untrusted. The default prompt fences it inside a
`<context>` block and instructs the model to treat it as data, not
instructions — reducing prompt-injection from malicious indexed content.
:::

## Streaming

LLM drivers expose a `stream()` method for token-by-token output (FR-GE-04).

## No LLM, no problem

With the default `null` LLM driver, `ask()->generate()` returns an empty answer
with the retrieved sources — ingestion and retrieval are entirely unaffected.
