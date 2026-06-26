<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;
use ZipArchive;

/**
 * DOCX (OOXML) parser (FR-PA-03) with no external dependency — a DOCX is a ZIP
 * of XML, read here with ZipArchive.
 *
 * Defences (FR-SEC-08): a zip-bomb guard caps total uncompressed size, and only
 * the fixed `word/document.xml` entry is read (no attacker-controlled paths).
 */
final class DocxParser implements Parser
{
    /**
     * Cap on total uncompressed size to defuse zip-bombs and memory-amplification
     * DoS. Kept well below a typical PHP memory_limit (128 MB) because the entry
     * is slurped whole and copied several times during extraction; a real DOCX
     * body is rarely more than a few MB.
     */
    /** Cap on the decompressed document.xml (well below memory_limit). */
    private const MAX_DOCUMENT_XML_BYTES = 12 * 1024 * 1024;

    /** Cap on paragraph count to bound parsing work. */
    private const MAX_PARAGRAPHS = 500_000;

    public function __construct(private readonly int $maxUncompressedBytes = 20 * 1024 * 1024) {}

    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rag_docx_');

        if ($tmp === false) {
            throw new ParsingException('Could not allocate a temporary file for DOCX parsing.');
        }

        file_put_contents($tmp, $contents);

        try {
            return $this->parseFile($tmp, $context);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function parseFile(string $path, array $context): ParsedDocument
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ParsingException('Not a valid DOCX (ZIP) file.');
        }

        $this->assertNotZipBomb($zip);

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new ParsingException('DOCX is missing word/document.xml.');
        }

        return $this->extract($xml, $context);
    }

    private function assertNotZipBomb(ZipArchive $zip): void
    {
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $total += (int) $stat['size'];

            if ($total > $this->maxUncompressedBytes) {
                $zip->close();
                throw new ParsingException('DOCX exceeds the maximum uncompressed size (possible zip-bomb).');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extract(string $xml, array $context): ParsedDocument
    {
        // Defuse the quadratic-regex DoS: cap the XML size and split paragraphs
        // with a LINEAR explode on the closing tag (never a backtracking
        // `<w:p>.*?</w:p>` over potentially-unterminated tags). FR-SEC-08.
        if (strlen($xml) > self::MAX_DOCUMENT_XML_BYTES) {
            throw new ParsingException('DOCX document.xml exceeds the maximum allowed size.');
        }

        $paragraphs = [];
        $sections = [];

        $segments = explode('</w:p>', $xml);

        if (count($segments) > self::MAX_PARAGRAPHS) {
            throw new ParsingException('DOCX has too many paragraphs (possible decompression bomb).');
        }

        foreach ($segments as $paragraphXml) {
            // <w:t> bodies are short, well-formed text runs — bounded match.
            preg_match_all('/<w:t\b[^>]*>([^<]*)<\/w:t>/', $paragraphXml, $tMatches);

            if ($tMatches[1] === []) {
                continue;
            }

            $text = html_entity_decode(implode('', $tMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');

            if (trim($text) === '') {
                continue;
            }

            $paragraphs[] = $text;

            if (preg_match('/<w:pStyle[^>]*w:val="(Heading|Title)[^"]*"/i', $paragraphXml) === 1) {
                $sections[] = new DocumentSection(type: 'heading', content: trim($text), level: 1);
            }
        }

        return new ParsedDocument(
            text: implode("\n\n", $paragraphs),
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            sections: $sections,
            metadata: array_filter(['filename' => $context['filename'] ?? null]),
        );
    }

    public function mimeTypes(): array
    {
        return [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'docx',
        ];
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
