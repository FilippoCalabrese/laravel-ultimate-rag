<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use Smalot\PdfParser\Parser as SmalotParser;
use Throwable;

/**
 * Text-PDF parser (FR-PA-01) backed by the pure-PHP smalot/pdfparser.
 *
 * The dependency is optional (suggested, not required) so search-only consumers
 * stay lean; {@see isAvailable()} reports whether it can be used. Each PDF page
 * is preserved as a section to keep page structure (FR-PA-10). Scanned PDFs need
 * the OCR seam (FR-PA-02) instead.
 */
final class PdfParser implements Parser
{
    public static function isAvailable(): bool
    {
        return class_exists(SmalotParser::class);
    }

    public function supports(string $mimeType): bool
    {
        return self::isAvailable() && in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        if (! self::isAvailable()) {
            throw new ParsingException('PDF parsing requires smalot/pdfparser (composer require smalot/pdfparser).');
        }

        try {
            $pdf = (new SmalotParser)->parseContent($contents);
        } catch (Throwable $e) {
            throw new ParsingException('Could not parse PDF: '.$e->getMessage(), previous: $e);
        }

        $sections = [];
        $pageTexts = [];

        foreach ($pdf->getPages() as $i => $page) {
            $pageText = trim($page->getText());
            $pageTexts[] = $pageText;
            $sections[] = new DocumentSection(type: 'page', content: $pageText, page: $i + 1);
        }

        $details = $pdf->getDetails();

        return new ParsedDocument(
            text: trim(implode("\n\n", $pageTexts)),
            mimeType: 'application/pdf',
            sections: $sections,
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'page_count' => count($pageTexts),
                'title' => is_string($details['Title'] ?? null) ? $details['Title'] : null,
                'author' => is_string($details['Author'] ?? null) ? $details['Author'] : null,
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['application/pdf', 'pdf'];
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
