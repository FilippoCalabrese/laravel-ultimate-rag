---
title: "Ingesting content"
description: "Ingest documents from text, files, URLs, storage and Eloquent records."
---

# Ingesting content

Ingestion turns any source into a versioned, deduplicated, encrypted `Document`.

## Sources

Build a source with the `SourceFactory` (via `Rag::source()`), then ingest it:

```php
use Sellinnate\RagEngine\Facades\Rag;

$source = Rag::source()->text('Some raw text to index.');
$document = Rag::ingest($source, ['tag' => 'note']);
```

All five origins are supported:

```php
Rag::source()->text($string);                       // FR-IN-02 raw text
Rag::source()->file('/path/to/report.pdf');         // FR-IN-01 upload / local file
Rag::source()->storage('s3', 'reports/q1.csv');     // FR-IN-05 cloud storage (S3/R2/local)
Rag::source()->url('https://example.com/page');     // FR-IN-03 URL fetch (SSRF-guarded)
Rag::source()->eloquent($model, ['title', 'body']); // FR-IN-04 Eloquent record
```

For Eloquent, implement `toRagContent(): string` on the model to control exactly
what text is ingested.

## Deduplication & idempotency

Ingesting the same content twice returns the **same** document — content is keyed
by a SHA-256 hash. Re-running an ingestion is safe.

```php
$a = Rag::ingest(Rag::source()->text('hello'));
$b = Rag::ingest(Rag::source()->text('hello'));
// $a->is($b) === true, only one row exists
```

## Versioning

Give a source a logical key (`document_key`, or a filename/url) and re-ingesting
changed content creates a new **version**, atomically superseding the previous
one:

```php
Rag::ingest(Rag::source()->text('v1', ['document_key' => 'doc-1'])); // version 1
Rag::ingest(Rag::source()->text('v2', ['document_key' => 'doc-1'])); // version 2, v1 superseded
```

## Provenance

Every document records its provenance: source type, checksum, MIME, size and an
ingestion timestamp, under `metadata.provenance`.

## Soft-delete & crypto-shredding

```php
$ingestor = Rag::ingestor();
$ingestor->softDelete($document); // recoverable, status = deleted
$ingestor->purge($document);      // physical purge: deletes the only wrapped DEK → unrecoverable
```

Purge crypto-shreds the document: because the wrapped DEK lives only in the
document row, deleting the row makes the *encrypted* content permanently
unrecoverable (even from DB backups) while the tenant key is still alive. The
document's plaintext vectors are also deleted from the live vector store.

::: callout warning "URL ingestion is SSRF-guarded"
`url()` rejects non-http(s) schemes and any host that resolves to a private,
loopback, link-local or reserved address (cloud metadata, localhost, internal
services). Redirects are not followed.
:::
