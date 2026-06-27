---
title: "Evaluating retrieval quality"
description: "Measure recall@k, precision@k, hit-rate and MRR over a labelled dataset so you can tune chunking, embedders and retrieval with numbers."
---

# Evaluating retrieval quality

How do you know your RAG setup is *good*? You measure it. The evaluation harness
runs a set of labelled queries through the real retrieval pipeline and reports
standard metrics, so you can compare chunking strategies, embedding models and
retrieval options (hybrid, rerank, MMR) with numbers instead of vibes.

::: callout info "In plain words"
You write down some questions and which documents *should* come back for each.
The harness runs the questions through search and tells you, as percentages, how
often the right documents showed up and how high they ranked. Change a setting,
re-run, compare.
:::

## The metrics

All are in 0–100% (MRR is a 0–1 score):

| Metric | Meaning |
|---|---|
| **Hit rate** | Fraction of queries with at least one relevant result in the top-k. |
| **Recall@k** | Mean fraction of a query's relevant documents found in the top-k. |
| **Precision@k** | Mean (relevant found in top-k) / k. |
| **MRR** | Mean Reciprocal Rank — how high the *first* relevant result ranks (1.0 = always first). |

## 1. Label a dataset

A dataset is a JSON array of cases. `relevant` lists the **document ids** (or
chunk ids) that should be retrieved for the query:

```json
[
  { "query": "how long do refunds take?", "relevant": ["doc-refunds-id"] },
  { "query": "how much holiday do I get?", "relevant": ["doc-leave-id"] }
]
```

A hit matches if either a result's **chunk id** or its **document id** is in the
`relevant` set — so you can label at whichever granularity you have.

## 2. Run it from the CLI

```bash
php artisan rag:evaluate path/to/dataset.json --k=5
# scope to a tenant, and try retrieval options:
php artisan rag:evaluate dataset.json --k=10 --tenant=acme --hybrid --rerank=cohere
```

Output:

```
+--------------+--------+
| Metric       | Value  |
+--------------+--------+
| Cases        | 25     |
| k            | 5      |
| Hit rate     | 88.0%  |
| Recall@k     | 81.2%  |
| Precision@k  | 19.0%  |
| MRR          | 0.7640 |
+--------------+--------+
```

::: callout tip "Reading precision@k"
Precision@k divides by *k*, so with one relevant doc per query and k=5 the ceiling
is 20%. That's expected — track **recall@k** and **MRR** for retrieval quality;
precision@k matters more when queries have many relevant docs.
:::

## 3. Or evaluate in code

```php
use Sellinnate\RagEngine\Evaluation\Evaluator;
use Sellinnate\RagEngine\Evaluation\EvaluationCase;

$cases = [
    new EvaluationCase('how long do refunds take?', ['doc-refunds-id']),
    new EvaluationCase('how much holiday do I get?', ['doc-leave-id']),
];

$report = app(Evaluator::class)->evaluateRetrieval($cases, k: 5, options: [
    'hybrid' => true,
    'rerank' => true,
    'reranker' => 'cohere',
]);

$report->recallAtK;     // 0.0–1.0
$report->mrr;
$report->toArray();     // full breakdown incl. per-case results
```

The `options` array accepts the same retrieval switches as the search builder:
`hybrid`, `rerank`, `reranker`, `mmr`, `threshold`, `filters`, `embedder`,
`store`, `namespace`.

## A tuning workflow

1. Build a small labelled set (20–50 real queries) from your domain.
2. Establish a baseline: `rag:evaluate set.json --k=5`.
3. Change **one** thing — chunk size, embedder, `--hybrid`, `--rerank` — and
   re-run.
4. Keep what improves **recall@k / MRR**; discard what doesn't.

::: callout warning "Use a real embedder"
The deterministic `fake` embedder has no semantic meaning, so its scores aren't
representative. Evaluate with the embedder you'll run in production.
:::

## Next

- **[Retrieval & search](/concepts/retrieval)** — the options you're tuning.
- **[Embedding & providers](/concepts/embedding)** — pick the right model.
