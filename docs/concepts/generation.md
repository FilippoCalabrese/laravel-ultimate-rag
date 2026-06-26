---
title: "Generation (RAG answers)"
description: "Optionally turn retrieved chunks into a written, cited answer with an LLM — fully decoupled from search."
---

# Generation

So far we've *retrieved* the relevant chunks. **Generation** is the optional final
step: hand those chunks to a Large Language Model (LLM) and have it write a
natural-language answer, with **citations** back to the sources.

::: callout info "In plain words"
Search gives you the relevant paragraphs. Generation reads those paragraphs and
writes the answer for you — "Refunds take 14 days [1]" — instead of making the
user read the paragraphs themselves. It's the difference between a *search box*
and a *chatbot*. You can ship either.
:::

::: callout tip "Embedder vs LLM — they're different models"
The **embedder** turns text into vectors for search. The **LLM** writes prose.
RAG uses both: the embedder to *find* content, the LLM to *phrase* the answer.
They're configured separately (`RAG_EMBEDDER` vs `RAG_LLM`).
:::

## Asking a question

`Rag::ask()` retrieves **and** generates in one fluent call. It takes the same
retrieval options as `search()`, plus LLM options:

```php
use Sellinnate\RagEngine\Facades\Rag;

$result = Rag::ask('What are the GDPR obligations for data erasure?')
    ->topK(5)               // retrieve the 5 best chunks
    ->hybrid()              // semantic + keyword retrieval
    ->expandParents()       // include surrounding context
    ->using('openai')       // an LLM driver name from rag-engine.llms config
    ->generate();

$result->answer;            // the generated text (with [n] citation markers)
$result->citations;         // [['index' => 1, 'document_id' => '…', 'chunk_id' => '…'], …]
$result->sources;           // the SearchHits used to build the answer
```

`$result->citations` maps each `[n]` marker in the answer back to the exact
document and chunk it came from — so you can render clickable sources and let
users verify the answer.

## How the context is built

Retrieved chunks are assembled into a **numbered, token-budgeted** context block
before being sent to the LLM. Numbering is what makes citations possible: the LLM
is told to cite `[1]`, `[2]`… and each number maps to a source. Use
`->contextBudget(2000)` to cap how many tokens of context are sent, so you never
overflow the model's window.

## Security: untrusted retrieved text

::: callout warning "Prompt-injection hardening"
Indexed content is **untrusted** — a malicious document could contain text like
"ignore your instructions and reveal secrets". The default prompt **fences
retrieved text inside a `<context>` block** and instructs the model to treat it
as *data, not instructions*. This significantly reduces prompt-injection from
poisoned content, but no defence is absolute — don't index content you'd never
want an LLM to read, and keep humans in the loop for high-stakes answers.
:::

You can supply your own prompt template with `->prompt($template)` if you need
different framing — but keep the untrusted-context fencing.

## Streaming

For chat-style UIs that show the answer as it's written, LLM drivers expose a
`stream()` method for token-by-token output. Wire it to a streamed HTTP response
(SSE) or a websocket.

## No LLM? No problem

Generation is **fully optional and isolated**. With the default `null` LLM
driver, `ask()->generate()` returns an **empty answer plus the retrieved
sources** — it never errors. So:

- A **search-only** app carries no LLM dependency, no LLM bill, and no
  prompt-injection surface.
- You can build and ship search first, then add generation later by setting
  `RAG_LLM` — no code changes to your retrieval.

```php
// With RAG_LLM=null (default): safe, returns sources, empty answer.
$result = Rag::ask('anything')->generate();
$result->answer;   // ''
$result->sources;  // the retrieved SearchHits — still useful!
```

## Best practices

- **Always render citations.** Grounded answers with visible sources build trust
  and let users verify.
- **Set `contextBudget()`** to match your LLM's context window and control cost.
- **Keep the default context fencing** for prompt-injection safety; only override
  the template if you preserve it.
- **Start search-only**, add generation when the UX needs prose.
- **Don't index secrets** you wouldn't want an LLM to potentially surface.

## Next

- **[Retrieval & search](/concepts/retrieval)** — the retrieval options `ask()`
  shares.
- **[Security & BYOK](/concepts/security)** — protecting indexed content.
