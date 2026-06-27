<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Ocr;
use Sellinnate\RagEngine\Managers\OcrManager;
use Sellinnate\RagEngine\Ocr\NullOcr;
use Sellinnate\RagEngine\Ocr\TesseractOcr;
use Sellinnate\RagEngine\Parsing\PdfParser;

final class FakePdfOcr implements Ocr
{
    public function ocr(string $contents, string $mimeType): string
    {
        return 'OCR_EXTRACTED_TEXT';
    }

    public function supports(string $mimeType): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fake';
    }
}

beforeEach(function () {
    $this->pdf = file_get_contents(__DIR__.'/../../fixtures/sample.pdf');
});

it('NullOcr is a no-op', function () {
    $ocr = new NullOcr;

    expect($ocr->ocr('x', 'application/pdf'))->toBe('')
        ->and($ocr->supports('application/pdf'))->toBeFalse()
        ->and($ocr->name())->toBe('null');
});

it('OcrManager resolves the null and tesseract engines', function () {
    expect(app(OcrManager::class)->driver('null'))->toBeInstanceOf(NullOcr::class)
        ->and(app(OcrManager::class)->driver('tesseract'))->toBeInstanceOf(TesseractOcr::class);
});

it('falls back to OCR when a PDF has too little text (scanned)', function () {
    // Force the OCR path by setting the threshold above the fixture's text length.
    $parser = new PdfParser(new FakePdfOcr, ocrMinChars: 1_000_000);

    $doc = $parser->parse($this->pdf, 'application/pdf');

    expect($doc->text)->toBe('OCR_EXTRACTED_TEXT')
        ->and($doc->metadata['ocr'])->toBeTrue();
});

it('keeps the native text layer and does not OCR a normal PDF', function () {
    $parser = new PdfParser(new FakePdfOcr, ocrMinChars: 0);

    $doc = $parser->parse($this->pdf, 'application/pdf');

    expect($doc->text)->not->toBe('OCR_EXTRACTED_TEXT')
        ->and($doc->metadata)->not->toHaveKey('ocr');
});

it('never OCRs when no OCR engine is configured', function () {
    $doc = (new PdfParser)->parse($this->pdf, 'application/pdf');

    expect($doc->metadata)->not->toHaveKey('ocr');
});
