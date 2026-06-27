<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Ocr;

use Sellinnate\RagEngine\Contracts\Ocr;

/**
 * No-op OCR (the default). Scanned PDFs/images simply yield no text — keeping the
 * package dependency-free until a consumer opts into a real OCR engine.
 */
final class NullOcr implements Ocr
{
    public function ocr(string $contents, string $mimeType): string
    {
        return '';
    }

    public function supports(string $mimeType): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'null';
    }
}
