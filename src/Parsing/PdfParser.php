<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Ocr;
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
 * is preserved as a section to keep page structure (FR-PA-10).
 *
 * Scanned (image-only) PDFs have no text layer; when an {@see Ocr} engine is
 * configured and the extracted text is below `ocrMinChars`, the parser falls
 * back to OCR (FR-PA-02).
 */
final class PdfParser implements Parser
{
    public function __construct(
        private readonly ?Ocr $ocr = null,
        private readonly int $ocrMinChars = 16,
    ) {}

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
        $text = trim(implode("\n\n", $pageTexts));
        $ocrUsed = false;

        // Scanned PDF: no (or too little) extractable text → fall back to OCR.
        if (mb_strlen($text) < $this->ocrMinChars
            && $this->ocr instanceof Ocr
            && $this->ocr->supports('application/pdf')) {
            $ocrText = trim($this->ocr->ocr($contents, 'application/pdf'));

            if ($ocrText !== '') {
                $text = $ocrText;
                $sections[] = new DocumentSection(type: 'ocr', content: $ocrText);
                $ocrUsed = true;
            }
        }

        return new ParsedDocument(
            text: $text,
            mimeType: 'application/pdf',
            sections: $sections,
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'page_count' => count($pageTexts),
                'ocr' => $ocrUsed ?: null,
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
