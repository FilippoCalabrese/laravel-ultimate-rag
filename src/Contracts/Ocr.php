<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

/**
 * Optical Character Recognition for scanned documents (FR-PA-02). Used as a
 * fallback when a PDF (or image) has no extractable text layer. Pluggable: the
 * default `null` engine does nothing; a `tesseract` engine shells out to
 * Tesseract. Implement this contract to plug in a cloud OCR (Textract, Vision…).
 */
interface Ocr
{
    /**
     * Extract text from raw file bytes (an image or a scanned PDF). Returns an
     * empty string when nothing could be read.
     */
    public function ocr(string $contents, string $mimeType): string;

    public function supports(string $mimeType): bool;

    public function name(): string;
}
