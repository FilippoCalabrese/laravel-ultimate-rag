---
title: "Parsing & formats"
description: "How raw files become clean text the engine can index — supported formats, structure preservation, and security."
---

# Parsing & formats

**Parsing** is the very first step of indexing: it takes a raw source (a PDF's
bytes, an HTML page, a CSV) and produces **clean, normalized text plus its
logical structure** (headings, tables, pages). Everything downstream — chunking,
embedding, search — works on that clean text, never the raw bytes.

::: callout info "In plain words"
Different file formats store text in wildly different ways. A parser is a
translator that reads one format and hands back plain text the rest of the engine
can understand — while throwing away noise (HTML `<script>` tags, formatting
markup) and remembering structure (which line was a heading).
:::

## How a parser is chosen

Each format is a separate `Parser` driver, picked automatically by the source's
**MIME type** (the standard "type tag" for content, like `text/csv` or
`application/pdf`). You normally never call a parser directly — `Rag::process()`
does it for you — but you can:

```php
use Sellinnate\RagEngine\Facades\Rag;

$parsed = Rag::parser()->parse($bytes, 'text/csv');

$parsed->text;       // the normalized, header-paired text
$parsed->sections;   // structural parts: headings, table rows, PDF pages…
$parsed->language;   // detected language (filled in during preprocessing)
```

## Built-in parsers

| Format | MIME type | Notes |
|---|---|---|
| Plain text | `text/plain` | Pass-through. |
| Markdown | `text/markdown` | Heading hierarchy becomes sections. |
| HTML | `text/html` | `<script>`/`<style>` stripped; DOM sanitized; no network access. |
| XML | `application/xml` | Hardened against XXE attacks (see below). |
| CSV / TSV | `text/csv` | Each cell paired with its column header, so rows stay meaningful. |
| JSON | `application/json` | Flattened to readable `path: value` lines. |
| DOCX | `application/vnd…wordprocessingml…` | Word files, read via PHP's `ZipArchive` — no external tools. |
| PDF | `application/pdf` | Text-based PDFs, via the optional `smalot/pdfparser` package. |

::: callout tip "PDFs are text, not images"
A PDF parser extracts the *text layer* of a PDF. A **scanned** document (an image
of text) has no text layer — for those, enable **OCR** (below).
:::

## Scanned PDFs & OCR

When a PDF yields little or no extractable text (a scan), the parser can fall
back to **OCR**. OCR is a pluggable engine, off by default:

| Driver (`RAG_OCR`) | What it does |
|---|---|
| `null` (default) | No OCR — scanned PDFs parse to empty. |
| `tesseract` | Shells out to the Tesseract binary (and `pdftoppm` to rasterise PDF pages). |

Enable it:

```dotenv
RAG_OCR=tesseract
# requires the `tesseract` (and, for PDFs, poppler's `pdftoppm`) binaries on the host
# RAG_OCR_LANG=eng
# RAG_OCR_MIN_CHARS=16     # below this many extracted chars, treat the PDF as a scan and OCR it
```

How the fallback works: after extracting the text layer, if its length is below
`ocr_min_chars` and an OCR engine is configured, the parser OCRs the file and uses
that text instead (marking `metadata.ocr = true`). Text PDFs are unaffected — OCR
only kicks in when there's nothing to extract.

::: callout info "Bring your own OCR engine"
Implement the `Sellinnate\RagEngine\Contracts\Ocr` contract and register it via
`OcrManager::extend()` to use a cloud OCR (AWS Textract, Google Vision, Azure) —
same seam, no parser changes. See **[Custom drivers](/guides/custom-drivers)**.
:::

## Structure is preserved, not flattened

Parsers keep a document's logical structure as a list of **`DocumentSection`**
objects — headings with their level, individual table rows, PDF pages. This is
what lets structure-aware chunkers (like the Markdown chunker) split on real
boundaries instead of mid-sentence. See **[Chunking](/concepts/chunking)**.

## Security hardening

Parsing **untrusted** files is a classic attack surface. The built-in parsers are
hardened against the common file-parsing exploits:

- **XXE (XML External Entity)** — a malicious XML file can try to make your server
  read local files or call internal URLs via "external entities". The XML parser
  **disables external entities and rejects any `DOCTYPE`** — including sneaky
  UTF-16-encoded ones.
- **Zip bombs (DOCX)** — a tiny `.docx` can decompress to gigabytes. The DOCX
  parser **caps total uncompressed size** (20 MB by default) and reads only the
  fixed `word/document.xml` entry, never attacker-chosen paths.
- **HTML** — parsed with **no network access**; scripts and styles are removed
  before text extraction.

::: callout warning "Always treat ingested files as untrusted"
These protections are on by default, but the safest posture is still to validate
uploads (size, MIME, source) at your application boundary before handing them to
ingestion.
:::

## Adding your own format

Parsing is extensible — register a parser for a new MIME type and it slots in
without touching the pipeline:

```php
use Sellinnate\RagEngine\Parsing\ParserManager;

app(ParserManager::class)->register(new MyEpubParser);
// The last parser registered for a given MIME type wins.
```

Full walkthrough: **[Custom drivers](/guides/custom-drivers)**.

## Best practices

- **Send the correct MIME type** when ingesting from raw bytes, so the right
  parser is chosen.
- **OCR scanned PDFs** to text before ingesting them.
- **Cap upload sizes** in your app, in addition to the engine's built-in limits.

## Next

- **[Preprocessing & PII](/concepts/preprocessing)** — what happens to the text
  after parsing.
