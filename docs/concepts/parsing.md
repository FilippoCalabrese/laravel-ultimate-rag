---
title: "Parsing & formats"
description: "Supported formats, structure preservation and security hardening."
---

# Parsing & formats

Parsing extracts normalized text plus logical structure from a source. Each
format is a pluggable `Parser` driver resolved by MIME type — adding a format is
an isolated addition, never a change to the pipeline (FR-PA-13).

## Built-in parsers

| Format | Driver | Notes |
|---|---|---|
| Plain text | `PlainTextParser` | pass-through |
| Markdown | `MarkdownParser` | heading hierarchy → sections |
| HTML | `HtmlParser` | script/style stripped, sanitized DOM |
| XML | `XmlParser` | XXE-hardened |
| CSV / TSV | `CsvParser` | table structure preserved (header-paired cells) |
| JSON | `JsonParser` | flattened to `path: value` lines |
| DOCX | `DocxParser` | OOXML via ZipArchive, no external dep |
| PDF | `PdfParser` | text PDFs via optional `smalot/pdfparser` |

```php
use Sellinnate\RagEngine\Facades\Rag;

$doc = Rag::parser()->parse($bytes, 'text/csv');
$doc->text;       // header-paired text
$doc->sections;   // structural sections (headings, tables, pages)
$doc->language;   // detected after preprocessing
```

## Structure preservation

Parsers keep logical structure as `DocumentSection`s — headings with levels,
table rows, PDF pages — rather than flattening it (FR-PA-09/10). Structure-aware
chunkers use these boundaries.

## Security hardening (FR-SEC-08)

- **XML/XXE**: external entity resolution is disabled and any DOCTYPE is
  rejected — including UTF-16-encoded DOCTYPEs that evade naive byte checks.
- **HTML**: parsed with no network access; scripts and styles removed.
- **DOCX/zip-bomb**: total uncompressed size is capped (20 MB default) and only
  the fixed `word/document.xml` entry is read — no attacker-controlled paths.

## Registering a new format

```php
use Sellinnate\RagEngine\Parsing\ParserManager;

app(ParserManager::class)->register(new MyEpubParser);
```
