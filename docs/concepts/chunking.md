---
title: "Chunking"
description: "Why documents are split into chunks, the strategies available, and how to choose size, overlap and strategy."
---

# Chunking

**Chunking** splits a document into small passages ("chunks") that become the
unit you search and retrieve. You don't search whole documents — a 50-page PDF is
far too coarse to be a useful answer. You search chunks.

::: callout info "In plain words"
Imagine highlighting the three sentences that answer a question. Chunking
pre-cuts every document into highlight-sized pieces so that, at search time, the
engine can hand back exactly the relevant piece — not the whole file.
:::

## Why chunk size matters

There's a trade-off baked into the chunk size:

- **Too big** → a chunk covers many topics, its vector becomes a blurry average,
  and retrieval gets imprecise. You also waste the LLM's context budget.
- **Too small** → a chunk lacks enough context to be meaningful on its own
  ("...it expires after 30 days." — *what* expires?).

A good default for prose is **~1000 characters with ~200 characters of overlap**.

::: callout tip "What is overlap?"
**Overlap** repeats the last bit of one chunk at the start of the next. It stops
a key sentence from being cut in half across a boundary and lost. ~10–20% of the
chunk size is a sensible amount.
:::

## Strategies

Every strategy is a swappable driver. Pick with the `strategy` option:

| Strategy | Driver | Best for | Trade-off |
|---|---|---|---|
| `recursive` | `RecursiveCharacterChunker` | General prose (**default**) | Splits on paragraph→line→word boundaries; good all-rounder. |
| `sentence` | `SentenceChunker` | Q&A, legal, anything where mid-sentence splits hurt | Never breaks a sentence; chunk sizes vary more. |
| `markdown` | `MarkdownChunker` | Structured docs with headings | Splits on heading boundaries; needs Markdown structure. |
| `fixed` | `FixedSizeChunker` | Uniform windows; token-budget control | Simple and predictable; can split mid-thought. |

```php
use Sellinnate\RagEngine\Facades\Rag;

$chunks = Rag::chunk($parsedDocument, [
    'strategy' => 'recursive',
    'size'     => 1000,   // characters
    'overlap'  => 200,
]);
```

You usually don't call `Rag::chunk()` directly — `Rag::process()` chunks for you
using the config defaults. Pass options to `process()` to override per document.

## Token-aware chunking

Embedding models have a maximum input length measured in **tokens** (≈ word
pieces), not characters. The `fixed` strategy can measure in tokens so chunks
never exceed the model's budget:

```php
Rag::chunk($doc, ['strategy' => 'fixed', 'unit' => 'tokens', 'size' => 512, 'overlap' => 50]);
```

## Parent-child (small-to-big)

A powerful pattern: embed **small** chunks for precise matching, but return a
**larger** surrounding chunk for context.

```php
Rag::chunk($doc, ['parent_child' => true, 'child_size' => 400, 'parent_size' => 2000]);
```

- **Children** (small) are embedded and searched → precise matches.
- **Parents** (large) are stored once; children reference theirs by index (no
  duplication).
- At retrieval, `->expandParents()` swaps each matched child for its richer parent
  so the LLM (or user) sees full context. See
  **[Retrieval & search](/concepts/retrieval)**.

::: callout tip "When to use parent-child"
Reach for it when your content is dense and precise wording matters (policies,
manuals, contracts) but answers need surrounding context. For short, self-
contained items (FAQ entries, product blurbs) plain chunking is simpler.
:::

## Contextual headers

By default each chunk is prefixed with a small **context header** — the document
title and section heading — which is included in the embedded text. This rescues
otherwise ambiguous chunks ("Revenue grew 12%." → *whose* revenue? *which*
year?):

```
Document: Annual Report 2024 > Section: Revenue

Revenue grew 12% year over year, driven by...
```

It's controlled by `rag-engine.chunking.contextual_headers` (on by default).

## Provenance on every chunk

Each chunk records where it came from: its `offset` in the document (with an
`offset_unit` of `char`, `word` or `sentence`), `token_count`, `chunk_index`, and
the inherited document metadata. This is what lets a search result link back to
its exact origin — see **[Eloquent models](/concepts/eloquent-models#every-chunk-is-traceable)**.

## Best practices

- **Start with `recursive`, ~1000/200.** Only change it if results disappoint.
- **Use `sentence`** when broken sentences would change meaning (legal, medical).
- **Use `markdown`** for docs that have a real heading structure.
- **Switch to token units** if you hit your embedder's input limit.
- **Add parent-child** when precise matches need surrounding context at answer
  time.
- **Keep contextual headers on** — they cost little and noticeably improve recall
  of ambiguous chunks.

## Common pitfalls

::: callout warning
- **Huge chunks → vague search.** If relevant results feel "close but not quite",
  your chunks are probably too large.
- **Tiny chunks → context-free answers.** Very small chunks match well but read
  poorly; pair them with parent-child.
- **Changing chunking doesn't update old vectors.** Re-process documents after
  changing chunk size/strategy so the index reflects the new chunks.
:::

## Next

- **[Embedding & providers](/concepts/embedding)** — turning chunks into vectors.
