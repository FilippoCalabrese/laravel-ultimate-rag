<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Parsing\DocxParser;
use Sellinnate\RagEngine\Parsing\PdfParser;

it('PdfParser extracts text and page sections (FR-PA-01)', function () {
    $contents = file_get_contents(__DIR__.'/../../fixtures/sample.pdf');

    $doc = (new PdfParser)->parse($contents, 'application/pdf', ['filename' => 'sample.pdf']);

    expect($doc->text)->toContain('Hello PDF World')
        ->and($doc->metadata['page_count'])->toBe(1)
        ->and($doc->sections[0]->type)->toBe('page')
        ->and($doc->sections[0]->page)->toBe(1);
})->skip(! PdfParser::isAvailable(), 'smalot/pdfparser not installed');

it('PdfParser reports availability and supported types', function () {
    expect(PdfParser::isAvailable())->toBeTrue()
        ->and((new PdfParser)->supports('application/pdf'))->toBeTrue()
        ->and((new PdfParser)->mimeTypes())->toContain('application/pdf');
})->skip(! PdfParser::isAvailable(), 'smalot/pdfparser not installed');

it('PdfParser raises a ParsingException on garbage input', function () {
    (new PdfParser)->parse('not a pdf at all', 'application/pdf');
})->skip(! PdfParser::isAvailable())->throws(ParsingException::class);

it('DocxParser extracts paragraphs and heading sections (FR-PA-03)', function () {
    $docx = buildDocx([
        ['text' => 'Document Title', 'heading' => true],
        ['text' => 'First paragraph body.', 'heading' => false],
        ['text' => 'Second paragraph body.', 'heading' => false],
    ]);

    $doc = (new DocxParser)->parse($docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($doc->text)->toContain('First paragraph body.')->toContain('Second paragraph body.')
        ->and($doc->sections)->toHaveCount(1)
        ->and($doc->sections[0]->content)->toBe('Document Title');
});

it('DocxParser rejects a non-zip payload', function () {
    (new DocxParser)->parse('plain text not a zip', 'docx');
})->throws(ParsingException::class, 'valid DOCX');

it('DocxParser rejects a zip missing document.xml', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('other.xml', '<x/>');
    $zip->close();
    $contents = file_get_contents($tmp);
    @unlink($tmp);

    (new DocxParser)->parse($contents, 'docx');
})->throws(ParsingException::class, 'missing word/document.xml');

/**
 * Build a minimal valid .docx in-memory.
 *
 * @param  list<array{text: string, heading: bool}>  $paragraphs
 */
function buildDocx(array $paragraphs): string
{
    $body = '';
    foreach ($paragraphs as $p) {
        $style = $p['heading'] ? '<w:pPr><w:pStyle w:val="Heading1"/></w:pPr>' : '';
        $body .= '<w:p>'.$style.'<w:r><w:t>'.htmlspecialchars($p['text']).'</w:t></w:r></w:p>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';

    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    $contents = file_get_contents($tmp);
    @unlink($tmp);

    return $contents;
}
