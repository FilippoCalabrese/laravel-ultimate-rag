<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use DOMDocument;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;

/**
 * XML parser with XXE defences (FR-PA-06, FR-SEC-08).
 *
 * Refuses documents declaring a DOCTYPE/ENTITY (the XXE vector) and never sets
 * LIBXML_NOENT, so entities are never expanded. Network access is blocked with
 * LIBXML_NONET. Extracts element text content.
 */
final class XmlParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        // Fast ASCII pre-check for the classic XXE entry point (clear error).
        if (preg_match('/<!DOCTYPE/i', $contents) === 1 || preg_match('/<!ENTITY/i', $contents) === 1) {
            throw new ParsingException('XML with a DOCTYPE/ENTITY declaration is rejected (XXE defence).');
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Robust defence (catches UTF-16/encoding tricks the regex misses):
        // disable external entity resolution entirely. No LIBXML_NOENT (would
        // expand entities); LIBXML_NONET blocks the network.
        libxml_set_external_entity_loader(static fn () => null);

        try {
            $loaded = $dom->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_set_external_entity_loader(null); // restore the default loader
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            throw new ParsingException('Malformed XML could not be parsed.');
        }

        // Reject any DTD that slipped past the pre-check (e.g. UTF-16 encoded).
        if ($dom->doctype !== null) {
            throw new ParsingException('XML with a DOCTYPE declaration is rejected (XXE defence).');
        }

        $text = $this->normalizeWhitespace($dom->textContent);

        return new ParsedDocument(
            text: $text,
            mimeType: 'application/xml',
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'root' => $dom->documentElement?->nodeName,
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['application/xml', 'text/xml', 'xml'];
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
