<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Parsing;

use Sellinnate\RagEngine\Contracts\Parser;
use Sellinnate\RagEngine\Data\DocumentSection;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * CSV/TSV parser (FR-PA-07). Preserves tabular structure rather than flattening
 * destructively (FR-PA-09): the table is kept as a section with header + rows,
 * and the text representation pairs each cell with its column header so a value
 * keeps its meaning after chunking.
 */
final class CsvParser implements Parser
{
    public function supports(string $mimeType): bool
    {
        return in_array($this->normalize($mimeType), $this->mimeTypes(), true);
    }

    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument
    {
        $delimiter = str_contains($this->normalize($mimeType), 'tab') || str_ends_with($this->normalize($mimeType), 'tsv')
            ? "\t"
            : $this->sniffDelimiter($contents);

        $lines = preg_split('/\R/u', rtrim($contents)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));

        if ($lines === []) {
            return new ParsedDocument('', 'text/csv');
        }

        $header = str_getcsv(array_shift($lines), $delimiter, '"', '\\');
        $rows = [];
        $textLines = [];

        foreach ($lines as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '\\');
            $row = [];
            $parts = [];

            foreach ($cells as $i => $value) {
                $column = $header[$i] ?? "col{$i}";
                $row[$column] = $value;
                $parts[] = "{$column}: {$value}";
            }

            $rows[] = $row;
            $textLines[] = implode(' | ', $parts);
        }

        $section = new DocumentSection(
            type: 'table',
            content: implode("\n", $textLines),
            metadata: ['header' => $header, 'rows' => $rows],
        );

        return new ParsedDocument(
            text: implode("\n", $textLines),
            mimeType: 'text/csv',
            sections: [$section],
            metadata: array_filter([
                'filename' => $context['filename'] ?? null,
                'columns' => $header,
                'row_count' => count($rows),
            ]),
        );
    }

    public function mimeTypes(): array
    {
        return ['text/csv', 'text/tab-separated-values', 'csv', 'tsv'];
    }

    private function sniffDelimiter(string $contents): string
    {
        $firstLine = strtok($contents, "\n") ?: '';

        $candidates = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($candidates);

        $best = array_key_first($candidates);

        return $candidates[$best] > 0 ? (string) $best : ',';
    }

    private function normalize(string $mimeType): string
    {
        return strtolower(trim($mimeType));
    }
}
