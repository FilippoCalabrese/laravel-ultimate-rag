<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\Ocr;
use Sellinnate\RagEngine\Ocr\NullOcr;
use Sellinnate\RagEngine\Ocr\TesseractOcr;

/**
 * Resolves the OCR engine (FR-PA-02). Default is the no-op `null` engine so the
 * package needs no OCR tooling until a consumer opts in (e.g. `tesseract`).
 *
 * @extends DriverManager<Ocr>
 */
final class OcrManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'ocr';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.ocr', 'null');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createNullDriver(array $config): Ocr
    {
        return new NullOcr;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTesseractDriver(array $config): Ocr
    {
        return new TesseractOcr(
            bin: (string) ($config['bin'] ?? 'tesseract'),
            lang: (string) ($config['lang'] ?? 'eng'),
            pdftoppm: (string) ($config['pdftoppm'] ?? 'pdftoppm'),
            timeout: (int) ($config['timeout'] ?? 120),
        );
    }
}
