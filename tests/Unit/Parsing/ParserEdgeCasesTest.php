<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Exceptions\ParsingException;
use Sellinnate\RagEngine\Parsing\CsvParser;
use Sellinnate\RagEngine\Parsing\DocxParser;
use Sellinnate\RagEngine\Parsing\HtmlParser;
use Sellinnate\RagEngine\Parsing\MarkdownParser;
use Sellinnate\RagEngine\Parsing\XmlParser;

it('MarkdownParser does not support unrelated types', function () {
    expect((new MarkdownParser)->supports('application/pdf'))->toBeFalse()
        ->and((new MarkdownParser)->mimeTypes())->toContain('md');
});

it('CsvParser returns an empty document for empty input', function () {
    expect((new CsvParser)->parse("\n\n", 'text/csv')->text)->toBe('')
        ->and((new CsvParser)->supports('application/json'))->toBeFalse();
});

it('CsvParser sniffs a semicolon delimiter', function () {
    $doc = (new CsvParser)->parse("a;b\n1;2", 'text/csv');

    expect($doc->metadata['columns'])->toBe(['a', 'b']);
});

it('HtmlParser handles documents without a title', function () {
    $doc = (new HtmlParser)->parse('<html><body><p>Body only</p></body></html>', 'text/html');

    expect($doc->metadata)->not->toHaveKey('title')
        ->and($doc->text)->toContain('Body only');
});

it('XmlParser rejects a bare ENTITY declaration (FR-SEC-08)', function () {
    (new XmlParser)->parse('<!ENTITY x "y"><root/>', 'application/xml');
})->throws(ParsingException::class, 'XXE');

it('XmlParser catches a UTF-16-encoded DOCTYPE that evades the ASCII pre-check (M2)', function () {
    $xml = '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE foo><root>x</root>';
    $utf16 = mb_convert_encoding($xml, 'UTF-16', 'UTF-8');

    // The ASCII regex cannot see the interleaved-null DOCTYPE; the post-parse
    // doctype check must still reject it.
    expect(preg_match('/<!DOCTYPE/i', $utf16))->toBe(0);

    (new XmlParser)->parse($utf16, 'application/xml');
})->throws(ParsingException::class, 'XXE');

it('DocxParser enforces the zip-bomb size cap (FR-SEC-08)', function () {
    // A tiny cap makes any real DOCX trip the uncompressed-size guard.
    $docx = buildDocx([['text' => 'some content', 'heading' => false]]);

    (new DocxParser(maxUncompressedBytes: 1))->parse($docx, 'docx');
})->throws(ParsingException::class, 'zip-bomb');
