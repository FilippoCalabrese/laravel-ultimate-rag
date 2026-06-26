<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Parsing\DocxParser;

/**
 * Build a .docx whose document.xml is the given raw XML.
 */
function docxWithXml(string $xml): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    $contents = file_get_contents($tmp);
    @unlink($tmp);

    return $contents;
}

it('parses a document with thousands of UNCLOSED <w:p> tags quickly (C-H1 DoS)', function () {
    // The old `<w:p>.*?</w:p>` regex went quadratic on unterminated tags.
    $xml = '<?xml version="1.0"?><w:document><w:body>'.str_repeat('<w:p><w:r><w:t>x</w:t></w:r>', 20000).'</w:body></w:document>';
    $docx = docxWithXml($xml);

    $start = hrtime(true);
    $doc = (new DocxParser)->parse($docx, 'docx');
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(500); // was ~8700ms for far fewer tags
    expect($doc->text)->toContain('x');
});

it('extracts text via linear paragraph splitting', function () {
    $xml = '<?xml version="1.0"?><w:document><w:body>'
        .'<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Title</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>Body text here.</w:t></w:r></w:p>'
        .'</w:body></w:document>';

    $doc = (new DocxParser)->parse(docxWithXml($xml), 'docx');

    expect($doc->text)->toContain('Body text here.')
        ->and($doc->sections[0]->content)->toBe('Title');
});

it('rejects an over-large document.xml (C-H1)', function () {
    $big = '<?xml version="1.0"?><w:document><w:body><w:p><w:r><w:t>'.str_repeat('a', 13 * 1024 * 1024).'</w:t></w:r></w:p></w:body></w:document>';

    (new DocxParser)->parse(docxWithXml($big), 'docx');
})->throws(ParsingException::class, 'maximum allowed size');
