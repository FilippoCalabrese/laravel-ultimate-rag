<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Parser for plain text (FR-PA-05). Pass-through extraction; structure is the
 * raw text. The cleaning pipeline normalizes whitespace afterwards.
 */
final class PlainTextParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        return new ParsedDocument(
            text: $contents,
            mimeType: $this->normalize($mimeType),
            metadata: array_filter(['filename' => $context['filename'] ?? null]),
        );
    }

    public function mimeTypes(): array
    {
        return ['text/plain', 'txt'];
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
