<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use JsonException;
use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Exceptions\ParsingException;

/**
 * JSON parser (FR-PA-07). Flattens the structure into readable "path: value"
 * lines so nested data keeps its context after chunking, while the decoded tree
 * is preserved in metadata.
 */
final class JsonParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ParsingException('Invalid JSON: '.$e->getMessage(), previous: $e);
        }

        $lines = [];
        $this->flatten($decoded, '', $lines);

        return new ParsedDocument(
            text: implode("\n", $lines),
            mimeType: 'application/json',
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'json' => is_array($decoded) ? $decoded : ['value' => $decoded],
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['application/json', 'text/json', 'json'];
    }

    /**
     * @param  list<string>  $lines
     */
    private function flatten(mixed $value, string $prefix, array &$lines): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                $this->flatten($child, $path, $lines);
            }

            return;
        }

        $scalar = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };

        $lines[] = $prefix === '' ? $scalar : "{$prefix}: {$scalar}";
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
