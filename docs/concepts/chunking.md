---
title: "Chunking"
description: "Splitting documents into indexable chunks with multiple strategies."
---

# Chunking

Chunking turns a parsed document into indexable fragments. Strategy, size and
overlap are all configurable, and every strategy is a pluggable driver
(FR-CH-10).

## Strategies

| Strategy | Driver | Best for |
|---|---|---|
| `fixed` | FixedSizeChunker | uniform windows (char or token unit) |
| `recursive` | RecursiveCharacterChunker | general prose (default) |
| `sentence` | SentenceChunker | never splitting mid-sentence |
| `markdown` | MarkdownChunker | structured docs (splits on headings) |

```php
use Sellinnate\RagEngine\Facades\Rag;

$chunks = Rag::chunk($parsedDocument, ['strategy' => 'recursive', 'size' => 1000, 'overlap' => 200]);
```

## Token-aware chunking

The `fixed` strategy can measure in tokens instead of characters so chunks
respect the embedding model's token budget (FR-CH-06):

```php
Rag::chunk($doc, ['strategy' => 'fixed', 'unit' => 'tokens', 'size' => 512, 'overlap' => 50]);
```

## Parent-child (small-to-big)

Enable parent-child chunking to embed small, precise child chunks while keeping
larger parents for context expansion at retrieval time (FR-CH-07). Parent text is
stored once; children reference their parent by index — no duplication.

```php
Rag::chunk($doc, ['parent_child' => true, 'child_size' => 400, 'parent_size' => 2000]);
```

## Contextual headers

By default each chunk is enriched with a contextual header (document title +
section heading) that is included in the embedded text, improving retrieval of
otherwise ambiguous chunks (FR-CH-08):

```
Document: Annual Report > Section: Revenue

<chunk content>
```

## Provenance

Each chunk records its `offset` (with an `offset_unit` of `char`, `word` or
`sentence`), `token_count`, `chunk_index` and inherited document metadata.
