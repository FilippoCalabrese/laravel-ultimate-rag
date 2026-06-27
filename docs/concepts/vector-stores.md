---
title: "Vector stores (storage & config)"
description: "The three vector-store backends, when to use each, and exactly how to configure them — including the Postgres connection for pgvector."
---

# Vector stores

A **vector store** is the database that holds your chunk vectors and finds the
ones nearest to a query. This page explains the three backends the package
ships, when to use each, and — step by step — how to configure them. New to the
idea of a vector? Read **[What is RAG?](/getting-started/what-is-rag)** first.

::: callout info "In plain words"
After a chunk is embedded into a vector (a list of numbers), it has to live
somewhere you can search quickly. That place is the vector store. You pick one
backend, point the package at it in `.env`, and everything else — indexing and
search — stays exactly the same.
:::

## Which backend should I use?

| Backend (`RAG_VECTOR_STORE`) | What it is | Use it when | Scale |
|---|---|---|---|
| `memory` | In-process, in-RAM | Tests & local dev | Tiny; **resets every run** |
| `pgvector` | SQL-backed (your database) | Small/medium apps, on-prem, "just use my DB" | ~up to tens of thousands of chunks |
| `qdrant` | A dedicated Qdrant server | Production at scale, fast approximate search | Millions+ |

You choose the default in `.env`:

```dotenv
RAG_VECTOR_STORE=pgvector   # memory | pgvector | qdrant
```

…or per query, without changing the default:

```php
Rag::search('q')->store('qdrant')->get();
```

All three implement the same contract, so **switching backend needs no code
changes** — only config and a re-index into the new store.

---

## 1. `memory` — zero config

The default. Vectors live in PHP memory for the current process, so there is
**nothing to configure**:

```dotenv
RAG_VECTOR_STORE=memory
```

::: callout warning "Not for production"
The in-memory store is wiped when the process ends and isn't shared between
workers or requests. It exists so the test suite and local experiments run with
no external services. Use `pgvector` or `qdrant` for anything real.
:::

---

## 2. `pgvector` — store vectors in your SQL database

This backend keeps vectors in two tables in a normal Laravel database connection
(`rag_vectors` and `rag_vector_namespaces`, created by the package migration). It
works on **Postgres, MySQL and SQLite**.

::: callout info "How it searches (be honest about scale)"
Today this driver scores candidates with a **filtered brute-force scan in PHP**
(tenant filtering is pushed down to SQL). That's correct and portable, and great
for up to roughly tens of thousands of chunks. It does **not yet** use the
Postgres `pgvector` extension's native ANN index — that's a planned optimisation.
For large corpora, use **[Qdrant](#3-qdrant--a-dedicated-vector-database)**.
:::

### 2a. The simplest setup — use your app's default database

If you're happy storing vectors in your app's existing database, you're almost
done. The package migration already created the `rag_vectors` tables there when
you ran `php artisan migrate`. Just select the driver:

```dotenv
RAG_VECTOR_STORE=pgvector
# RAG_PGVECTOR_CONNECTION is left unset → uses your DB_CONNECTION default
```

That's it. SQLite, MySQL or Postgres — whatever your app already uses — works.

### 2b. Using a dedicated Postgres connection (the common question)

**"Where do I specify the Postgres connection?"** — in your app's
`config/database.php`, exactly like any other Laravel connection, then point the
package at it by name.

**Step 1 — define a Postgres connection** in `config/database.php`:

```php
// config/database.php
'connections' => [
    // ...your existing connections...

    'pgsql' => [
        'driver'   => 'pgsql',
        'host'     => env('DB_PG_HOST', '127.0.0.1'),
        'port'     => env('DB_PG_PORT', '5432'),
        'database' => env('DB_PG_DATABASE', 'rag'),
        'username' => env('DB_PG_USERNAME', 'postgres'),
        'password' => env('DB_PG_PASSWORD', ''),
        'charset'  => 'utf8',
        'prefix'   => '',
        'search_path' => 'public',
        'sslmode'  => 'prefer',
    ],
],
```

**Step 2 — set the credentials** in `.env`:

```dotenv
DB_PG_HOST=127.0.0.1
DB_PG_PORT=5432
DB_PG_DATABASE=rag
DB_PG_USERNAME=postgres
DB_PG_PASSWORD=secret
```

**Step 3 — tell the package to use that connection**:

```dotenv
RAG_VECTOR_STORE=pgvector
RAG_PGVECTOR_CONNECTION=pgsql      # the connection NAME from config/database.php
```

**Step 4 — make sure the RAG tables exist on that connection.** This is the easy
thing to miss: `php artisan migrate` ran the package migrations on your *default*
connection. If `pgsql` is a *different* database, run them there too:

```bash
php artisan migrate --database=pgsql
```

::: callout tip "How the connection is resolved"
The package reads `rag-engine.vector_stores.pgvector.connection`
(`RAG_PGVECTOR_CONNECTION`). If it's **null/unset**, it uses Laravel's **default**
connection (`DB_CONNECTION`). If set, it uses that named connection — which must
contain the `rag_vectors` and `rag_vector_namespaces` tables.
:::

### The config block

```php
// config/rag-engine.php
'vector_stores' => [
    'pgvector' => [
        'driver'     => 'pgvector',
        'connection' => env('RAG_PGVECTOR_CONNECTION'), // null = app default connection
        'table'      => 'rag_vectors',                  // override if you renamed it
    ],
],
```

### Optional: the real `pgvector` Postgres extension

If you *want* the native Postgres vector extension on your database (e.g. for your
own queries), install it once:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

Managed Postgres (Neon, Supabase, RDS, Cloud SQL) all support enabling it from
their dashboard. Note: the engine doesn't *require* the extension today (it scans
in PHP), so this is optional until native ANN ships.

---

## 3. `qdrant` — a dedicated vector database

[Qdrant](https://qdrant.tech) is a purpose-built vector database with fast
approximate-nearest-neighbour (ANN) search. Use it when your corpus is large or
search latency matters. It's open-source and EU self-hostable.

**Step 1 — run Qdrant** (Docker is easiest):

```bash
docker run -p 6333:6333 qdrant/qdrant
```

**Step 2 — point the package at it** in `.env`:

```dotenv
RAG_VECTOR_STORE=qdrant
RAG_QDRANT_HOST=http://localhost:6333
RAG_QDRANT_API_KEY=                 # set this if your Qdrant requires auth
# RAG_QDRANT_QUANTIZATION=scalar    # optional: scalar | binary (cuts memory/cost)
```

### The config block

```php
'vector_stores' => [
    'qdrant' => [
        'driver'        => 'qdrant',
        'host'          => env('RAG_QDRANT_HOST', 'http://localhost:6333'),
        'api_key'       => env('RAG_QDRANT_API_KEY'),
        'quantization'  => env('RAG_QDRANT_QUANTIZATION'), // scalar | binary | null
    ],
],
```

::: callout tip "Quantization = cheaper memory"
`quantization` compresses stored vectors inside Qdrant. `scalar` (int8) cuts
memory ~4× with minimal accuracy loss; `binary` is the most aggressive. Leave it
unset for full precision. It's applied when a collection is first created, so set
it before indexing.
:::

The engine creates one Qdrant **collection per tenant namespace** automatically;
you don't pre-create anything.

---

## Migrating between backends

Vectors are tied to the embedding model that produced them and to the store
they live in — they don't transfer automatically. To switch backend (or
embedding model), **re-index your corpus** into the new store:

1. Change `RAG_VECTOR_STORE` (and any backend env vars).
2. Re-run processing for your documents (`Rag::process()` /
   `ProcessDocumentJob`), or re-save your embeddable models.

## Best practices

- **Dev/tests: `memory`. Small/medium prod: `pgvector`. Large prod: `qdrant`.**
- **Keep vectors near your app** for `pgvector` — a slow DB connection slows
  every search.
- **Run `php artisan migrate --database=<conn>`** whenever `pgvector` points at a
  non-default connection.
- **Set Qdrant `quantization`** before the first index if memory/cost matters.
- **Re-index after changing backend or embedding model** — vectors aren't
  portable across either.

## Common pitfalls

::: callout warning
- **`pgvector` + a custom connection but "table not found"** → you didn't run the
  migrations on that connection (`php artisan migrate --database=pgsql`).
- **`RAG_VECTOR_STORE=database`** → there is no `database` driver; the SQL store
  is named **`pgvector`**.
- **Switched store and search is empty** → you must re-index; vectors don't move
  between stores.
- **Qdrant search fails** → check `RAG_QDRANT_HOST` is reachable and the API key
  (if any) is set.
:::

## Next

- **[Retrieval & search](/concepts/retrieval)** — querying whatever store you chose.
- **[Configuration](/getting-started/configuration)** — all the config blocks.
