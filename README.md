# RAG Engine for Laravel

[![Tests](https://img.shields.io/badge/tests-passing-brightgreen)]()
[![Coverage](https://img.shields.io/badge/coverage-%E2%89%A590%25-brightgreen)]()
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-blue)]()

Enterprise **Retrieval-Augmented Generation** engine for Laravel: ingestion,
parsing, chunking, embedding, vector search, reranking, BYOK security and
multi-tenancy — all behind stable contracts.

> Infrastructure, not a feature. The engine owns the whole pipeline from
> *ingestion → retrieval*. Generation is an optional, decoupled layer.

## Principles

- **Domain-agnostic** generic primitives (documents, chunks, queries, results).
- **Contract-first** — every replaceable component sits behind an interface.
- **Async-first** ingestion; synchronous, low-latency retrieval.
- **Multi-tenant & secure by design** — per-tenant isolation and BYOK envelope encryption.
- **EU-resident by default** for data and embeddings.

## Install

```bash
composer require sellinnate/rag-engine
php artisan vendor:publish --tag="rag-engine-config"
php artisan vendor:publish --tag="rag-engine-migrations"
php artisan migrate
```

## Quick start

```php
use Sellinnate\RagEngine\Facades\Rag;

// BYOK envelope encryption
$payload = Rag::encrypter()->encrypt('confidential text', 'tenant-42');
$plain   = Rag::encrypter()->decrypt($payload);

// Crypto-shredding (GDPR right to erasure)
Rag::kms()->destroyKey('tenant-42');

// Tenant-scoped work
Rag::forTenant('tenant-7', fn () => /* ... */);
```

## Documentation

Full docs are built with [docmd](https://docmd.io) from `docs/` into `site/`:

```bash
npx docmd dev    # local preview
npx docmd build  # static site into site/
```

## Development

```bash
composer test                                    # run the suite
vendor/bin/phpstan analyse                        # static analysis (level 8)
vendor/bin/pint                                   # code style

# Coverage (requires a coverage driver, e.g. Xdebug/PCOV):
XDEBUG_MODE=coverage vendor/bin/pest --coverage --min=90
```

## License

MIT. See [LICENSE.md](LICENSE.md).
