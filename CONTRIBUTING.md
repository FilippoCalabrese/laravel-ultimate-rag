# Contributing

Thanks for your interest in improving **RAG Engine for Laravel**! This guide gets
you set up and explains the quality bar a PR needs to meet.

## Getting started

```bash
git clone https://github.com/Sellinnate/laravel-ultimate-rag
cd laravel-ultimate-rag
composer install
```

Everything runs offline by default (deterministic `fake` embedder, in-memory
vector store, local KMS), so no API keys or external services are needed to
develop or run the test suite.

## Quality gates (all required, all green)

Run these before opening a PR — CI enforces every one:

```bash
composer test            # Pest suite
composer analyse         # PHPStan, level 8 (no errors, no new baseline entries)
composer format          # Laravel Pint (code style)

# Coverage (needs Xdebug/PCOV):
XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=90
```

Guidelines:

- **Tests are mandatory** for any behaviour change or new feature. Mirror the
  existing style (Pest, `Http::fake` for providers, deterministic drivers). Tests
  needing external services (e.g. native pgvector) must be **gated and skipped**
  when the service isn't available — see `PgVectorStoreTest`.
- **PHPStan level 8 must pass** with no new errors. Don't suppress with
  `@phpstan-ignore`, baseline entries, or unsound casts — fix the underlying type.
- **Pint** must report no style changes.
- **Coverage ≥ 90%.** Integration-only code that can't run in the SQLite suite may
  be excluded with a documented `@codeCoverageIgnore` (e.g. native pgvector).

## Architecture conventions

- The engine is **contract-first**: depend on interfaces in `Contracts/`, not
  concrete drivers. New providers are **drivers** registered via a `*Manager`
  (`createXxxDriver()` or `extend()`), never edits to the pipeline.
- Keep the public surface behind the **`Rag` facade** and the contracts.
- Document every new feature on the docs site (`docs/`, built with docmd) — see
  the [docs](https://laravel-rag-engine.selli.io). Update `README.md` and
  `.env.example` when you add config/env vars.

## Pull requests

1. Branch from `main`.
2. Keep PRs focused; write a clear description of *what* and *why*.
3. Ensure all gates above are green and docs are updated.
4. Be kind in review — we're all here to build something solid.

## Reporting bugs / security

- Bugs and features: open a [GitHub issue](https://github.com/Sellinnate/laravel-ultimate-rag/issues).
- **Security vulnerabilities**: do **not** open a public issue — see
  [SECURITY.md](SECURITY.md).

## License

By contributing, you agree your contributions are licensed under the project's
[MIT license](LICENSE.md).
