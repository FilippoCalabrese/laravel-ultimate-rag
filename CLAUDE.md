# CLAUDE.md

Operating guide for working on **RAG Engine for Laravel** (`sellinnate/rag-engine`).
Follow this methodology for every task in this repository. It encodes how the
project is built, tested, documented and released — keep new work consistent with it.

## What this project is

A Laravel package providing Retrieval-Augmented Generation infrastructure:
ingestion → parsing → preprocessing/PII → chunking → embedding → vector storage →
retrieval (hybrid/MMR/rerank/multi-query) → optional generation. Plus embeddable
Eloquent models, BYOK security, multi-tenancy, evaluation and OCR.

- **Package name:** `sellinnate/rag-engine` · **Namespace:** `Sellinnate\RagEngine` · **Facade:** `Rag`
- **Repo:** https://github.com/Sellinnate/laravel-ultimate-rag (remote `origin`)
- **Docs site:** https://laravel-rag-engine.selli.io (docmd, hosted on Cloudflare, rebuilt from `main`)
- **Requirements:** PHP `^8.2`, Laravel 11/12/13 (`illuminate/contracts ^11||^12||^13`)

## Golden rules

1. **Everything we claim must exist.** Never document or advertise a feature,
   driver, config key or env var that isn't implemented and wired. If something
   is roadmap, say so explicitly; if a config value is unsupported, **fail closed**
   (throw a clear exception) rather than silently no-op.
2. **All quality gates green before any commit** (tests, PHPStan L8, Pint,
   coverage ≥90%, `composer validate`).
3. **Docs and code change together.** Any behaviour/feature/config change updates
   the docmd site (`docs/`), `README.md`, `.env.example` and `config/rag-engine.php`
   in the same change.
4. **Junior-friendly docs.** Define jargon, give concrete runnable examples, list
   best practices and common pitfalls. Don't assume prior RAG knowledge.
5. **Keep secrets out of committed files.** Provider keys are read via `env()` in
   config; the config file is committed and `config:cache`-safe.

## Architecture & conventions

- **Contract-first.** Every replaceable component is an interface in
  `src/Contracts`. Application code and internals depend on contracts + the `Rag`
  facade, never on concrete drivers.
- **Driver Manager pattern.** Each subsystem (embedders, vector stores, rerankers,
  LLMs, KMS, tokenizers, OCR, chunkers, parsers) is fronted by a `*Manager`
  extending `DriverManager`. Add a provider by implementing the contract +
  `create<Studly>Driver(array $config)` (or `extend()`), **never** by editing the
  pipeline. New drivers must be selectable purely via config (`defaults.*` + the
  named-connections block) — no application code changes to switch provider.
- **Decorators** wrap drivers for cross-cutting concerns (`RetryingEmbedder`,
  `RetryingLlm`, `RetryingReranker`, `CachingEmbedder`). Provider HTTP drivers
  retry transient failures (429/5xx/connection) with backoff and fail fast on 4xx.
- **HTTP providers** share a small base (`HttpEmbedder`/`OpenAiCompatibleEmbedder`,
  `HttpLlm`, `HttpReranker`) and throw typed exceptions carrying status +
  `retryable` (`EmbeddingException`, `ProviderException`).
- **Public surface = the `Rag` facade + the contracts.** Add facade `@method`
  docblocks when you add an accessor.

## Development workflow

1. Branch from `main` (e.g. `feature/...`, `fix/...`, `docs/...`).
2. Implement following the patterns above. Match surrounding code style, comment
   density and naming.
3. Add/extend tests (mandatory — see below).
4. Update docs/config/.env/README.
5. Run all gates green.
6. Commit, merge to `main` (`--no-ff`), push. (This repo's flow has been: work on
   a branch, then merge into `main` and push.)

> Note: the repo has a `Fix PHP code style issues` GitHub Action that may
> auto-commit Pint fixes, and an `Update Changelog` action on release. After a
> push/release, `git pull --rebase origin main` before the next push.

## Testing methodology

- **Framework:** Pest 4. Tests live in `tests/Unit` and `tests/Feature`.
- **Offline & deterministic by default:** use the `fake` embedder, in-memory
  vector store and `local` KMS. Use `Http::fake()` (and `Aws\MockHandler` for AWS)
  for provider tests — **never hit a real network/service** in the standard suite.
- **Cover the real behaviour, not just happy paths:** assert request shape
  (endpoint, auth headers, payload), response mapping, error/retry paths, and
  invariants (tenant isolation, no orphan vectors, fail-closed guards).
- **Integration tests that need an external service must be gated** and skip when
  the service is absent (see `PgVectorStoreTest`, gated on `RAG_PGVECTOR_TEST`).
  Add a dedicated CI job to actually run them (the `pgvector` job boots a real
  Postgres+pgvector service).
- **Coverage ≥ 90%** (`--min=90`). Code that genuinely can't run in the SQLite
  suite (native pgvector, Tesseract shell-out) is excluded with a documented
  `@codeCoverageIgnore` **and** is integration-tested in CI instead — do not lower
  the bar for ordinary code.

## Quality gates (run before every commit)

```bash
composer test            # Pest suite
composer analyse         # PHPStan level 8 — zero errors
composer format          # Laravel Pint — zero style changes
composer validate --strict

# Coverage (needs a coverage driver; CI runs tests without coverage):
XDEBUG_MODE=coverage /opt/homebrew/bin/php -d memory_limit=1G vendor/bin/pest --coverage --min=90
```

PHPStan rules (level 8): **fix the underlying type**, never silence. No
`@phpstan-ignore`, no new baseline entries, no `assert()`/inline `@var` overrides,
no casts-to-silence, no widening params/returns just to pass.

> **PHPStan CI caveat:** the standalone `PHPStan` workflow installs the *latest*
> larastan on PHP 8.5, which can flag things older local deps don't. If you touch
> typing-sensitive code, reproduce CI locally with `composer update` (to pull the
> latest larastan) **then** run `composer analyse` before pushing.

## Documentation alignment (required for every change)

The docs site is **docmd** (`@mgks/docmd`/`@docmd/core`), sources in `docs/`,
built to `site/` (gitignored). Cloudflare rebuilds from `main` on push.

- Build/preview: `npm run docs:dev` / `npm run docs:build`. Output dir `site`.
- **Every new feature/driver/config gets documented** on the site, in `README.md`,
  in `.env.example` and in `config/rag-engine.php` comments.
- **Conventions:**
  - Callouts use **three colons**: `::: callout tip "Title"` … `:::`. Keep fences
    balanced (open count == close count).
  - For cross-page anchor links, add an **explicit `{#id}`** to the target heading
    (docmd auto-anchors are prefixed with the page slug, so bare `#heading` links
    break). Verify anchors against the built HTML.
  - Add new pages to the nav in `docmd.config.json`.
  - Strip internal requirement codes (`FR-xx`/`NFR-xx`) from user-facing pages.
- **After doc changes:** run `npm run docs:build`, then verify: page built, no
  stray `^:::` fences in `site/**.html`, callouts rendered, internal links/anchors
  resolve. Technical notes go under `docs/technical/` (one per cycle).
- Keep test counts and feature lists in `README.md` accurate.

## Config & environment conventions

- `config/rag-engine.php` is committed and `config:cache`-safe: scalars/arrays/
  `env()` only, **no closures, no secrets**.
- API keys/secrets: `env(...)` in config, real values only in `.env`. Mirror every
  variable in `.env.example` with a short comment.
- Pattern per subsystem: a `defaults.<subsystem>` selector + a named-connections
  block. Remove dead config keys rather than leaving them unwired.

## Security & data posture (preserve these)

- **BYOK envelope encryption** + **crypto-shredding** (destroy the key, not the
  copies). KMS drivers: `local` (dev), `aws` (production, one CMK per tenant).
- **PII redaction ON by default** before content leaves for embedding/index.
- **Fail-closed multi-tenancy:** every query is scoped to the current tenant;
  scope can never be widened from a query. Only `namespace` isolation is supported.
- **EU-resident by default:** prefer EU/self-hosted providers; non-EU providers are
  opt-in and documented as such.
- **Untrusted inputs are hardened:** SSRF-guarded URL ingestion, XXE/zip-bomb-safe
  parsers, prompt-injection-fenced generation context, WORM audit log.
- Binary test fixtures are marked `binary` in `.gitattributes` (Windows CI uses
  `autocrlf=true` and would otherwise corrupt them).

## Release process

1. Ensure `main` is green in CI (run-tests matrix + PHPStan + pgvector job).
2. Annotated tag `vX.Y.Z` (semver: feature → minor, fix → patch) + push the tag.
3. Create a GitHub Release with notes (`gh release create vX.Y.Z --verify-tag`).
4. **Packagist publish is a manual user step** (the auto-update webhook isn't
   configured): the maintainer clicks "Update" on the Packagist package page, or
   triggers it via the Packagist API token. Do not assume a tag auto-appears on
   Packagist.

## Commit messages

- Conventional-style subject (`feat:`, `fix:`, `docs:`, `chore:`, `ci:`, `test:`).
- Explain *what* and *why*; note the gates passed.
- End every commit (and PR body) with:
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

## Key commands

```bash
composer test            # run tests
composer analyse         # PHPStan L8
composer format          # Pint
npm run docs:build       # build the docs site into ./site
npm run docs:dev         # preview docs locally
```
