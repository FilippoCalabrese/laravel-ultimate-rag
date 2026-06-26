<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Markdown parser (FR-PA-05). Preserves the heading hierarchy as logical
 * sections (FR-PA-10) so structure-aware chunkers can respect boundaries, while
 * keeping the original text intact.
 */
final class MarkdownParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $sections = [];

        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $sections[] = new DocumentSection(
                    type: 'heading',
                    content: trim($m[2]),
                    level: strlen($m[1]),
                );
            }
        }

        return new ParsedDocument(
            text: $contents,
            mimeType: 'text/markdown',
            sections: $sections,
            metadata: array_filter(['filename' => $context['filename'] ?? null]),
        );
    }

    public function mimeTypes(): array
    {
        return ['text/markdown', 'text/x-markdown', 'md', 'markdown'];
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
