<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use DOMDocument;
use DOMNode;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * HTML parser (FR-PA-06). Sanitizes markup: script/style are dropped, the DOM
 * is loaded with no network access (LIBXML_NONET) and no external entity
 * resolution (FR-SEC-08). Headings become logical sections (FR-PA-10).
 */
final class HtmlParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET blocks network fetches; HTML parsing does not expand
        // external entities, but we keep the hardened flags for defence in depth.
        $dom->loadHTML(
            '<?xml encoding="UTF-8">'.$contents,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->removeNodes($dom, ['script', 'style', 'noscript']);

        $sections = [];
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $level => $tag) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $sections[] = new DocumentSection(
                    type: 'heading',
                    content: trim($node->textContent),
                    level: $level + 1,
                );
            }
        }

        $title = $this->firstTagText($dom, 'title');
        $body = $dom->getElementsByTagName('body')->item(0);
        // DOMDocument::loadHTML always wraps content in a body element.
        $text = $this->normalizeWhitespace($body instanceof DOMNode ? $body->textContent : $dom->textContent);

        return new ParsedDocument(
            text: $text,
            mimeType: 'text/html',
            sections: $sections,
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'title' => $title,
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['text/html', 'application/xhtml+xml', 'html', 'htm'];
    }

    /**
     * @param  list<string>  $tags
     */
    private function removeNodes(DOMDocument $dom, array $tags): void
    {
        foreach ($tags as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));

            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function firstTagText(DOMDocument $dom, string $tag): ?string
    {
        $node = $dom->getElementsByTagName($tag)->item(0);
        $text = $node instanceof DOMNode ? trim($node->textContent) : '';

        return $text === '' ? null : $text;
    }

    private function normalizeWhitespace(string $text): string
    {
        // Horizontal whitespace runs → single space.
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        // Newlines (with surrounding spaces) → single newline.
        $text = preg_replace('/ *\n[ \n]*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
