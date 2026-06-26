---
title: "Preprocessing & PII"
description: "Composable cleaning, language detection and PII redaction."
---

# Preprocessing & PII redaction

After parsing, a composable pipeline of stages normalizes the document and — by
default — redacts personal data before it ever reaches an embedding provider or
the index (FR-PP, NFR-CO-04).

## The pipeline

Stages run in the order configured under `rag-engine.preprocessing.stages`:

```php
'stages' => ['text-cleaner', 'language-detector', 'pii-redactor'],
```

- **text-cleaner** — UTF-8 normalization, whitespace collapse, control/zero-width stripping.
- **language-detector** — IT/DE/EN detection by stopword frequency.
- **pii-redactor** — detects and redacts PII (ON by default).

## PII redaction

Detected types: e-mail, credit cards (Luhn-validated), IBAN (mod-97 validated),
Italian *codice fiscale* and *P.IVA*, and phone numbers.

::: callout warning "Redaction covers the whole document"
PII is redacted not only in the flat text but in **section content, section
metadata, and the document metadata tree** — so values hiding in CSV rows, JSON
fields or PDF page sections are scrubbed too.
:::

### Strategies

```php
'pii_strategy' => 'mask',     // [EMAIL]                — destructive (default)
'pii_strategy' => 'tokenize', // [EMAIL:ab12cd] + map   — reversible by the consumer
```

In `tokenize` mode a token→original map is recorded in metadata so the consumer
can reverse the redaction where authorized; the same value always maps to the
same token.

### Robustness

Real-world formats are handled: spaced/lowercase IBANs (`de89 3704 …`), cards
with dot or non-breaking-space separators, etc. When a greedy match over-reaches
into a following word, the redactor trims back to the longest validating value so
only the real PII is replaced.

## Disabling

PII redaction is on by default. To turn it off (not recommended):

```php
'pii_redaction_enabled' => env('RAG_PII_REDACTION', false),
```
