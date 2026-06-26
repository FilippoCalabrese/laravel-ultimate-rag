<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Fixed-size chunker (FR-CH-01) with configurable size and overlap. Supports a
 * character unit (default) or a token unit (FR-CH-06) so boundaries can respect
 * the model's token budget rather than raw characters.
 */
final class FixedSizeChunker extends AbstractChunker
{
    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $size = max(1, (int) $this->option($options, 'size', 1000));
        $overlap = max(0, min((int) $this->option($options, 'overlap', 200), $size - 1));
        $unit = (string) $this->option($options, 'unit', 'chars');

        return $unit === 'tokens'
            ? $this->chunkByTokens($document, $size, $overlap)
            : $this->chunkByChars($document, $size, $overlap);
    }

    /**
     * @return list<TextChunk>
     */
    private function chunkByChars(ParsedDocument $document, int $size, int $overlap): array
    {
        $text = $document->text;
        $length = mb_strlen($text);

        if ($length === 0) {
            return [];
        }

        $step = $size - $overlap;
        $chunks = [];
        $index = 0;

        for ($offset = 0; $offset < $length; $offset += $step) {
            $piece = mb_substr($text, $offset, $size);

            if (trim($piece) === '') {
                continue;
            }

            $chunks[] = $this->makeChunk($piece, $index++, $offset, $document->metadata, ['offset_unit' => 'char']);
        }

        return $chunks;
    }

    /**
     * @return list<TextChunk>
     */
    private function chunkByTokens(ParsedDocument $document, int $size, int $overlap): array
    {
        $words = preg_split('/\s+/u', trim($document->text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return [];
        }

        // Pre-tokenize each word once (O(n)); accumulate per-word counts rather
        // than re-tokenizing the whole growing window each step (M1 perf fix).
        $wordTokens = array_map(fn (string $w): int => max(1, $this->tokenizer->count($w)), $words);

        $chunks = [];
        $index = 0;
        $i = 0;
        $wordCount = count($words);

        while ($i < $wordCount) {
            $tokens = 0;
            $j = $i;

            // Always take at least one word, then add while within budget.
            while ($j < $wordCount && ($j === $i || $tokens + $wordTokens[$j] <= $size)) {
                $tokens += $wordTokens[$j];
                $j++;
            }

            $piece = implode(' ', array_slice($words, $i, $j - $i));
            // offset is a WORD index here, flagged via offset_unit (M2).
            $chunks[] = $this->makeChunk($piece, $index++, $i, $document->metadata, ['offset_unit' => 'word']);

            $advance = max(1, ($j - $i) - $this->overlapWordCount($wordTokens, $i, $j, $overlap));
            $i += $advance;
        }

        return $chunks;
    }

    /**
     * Number of trailing words in [$start,$end) whose token sum stays within the
     * overlap budget (never the whole window).
     *
     * @param  list<int>  $wordTokens
     */
    private function overlapWordCount(array $wordTokens, int $start, int $end, int $overlapTokens): int
    {
        if ($overlapTokens <= 0) {
            return 0;
        }

        $count = 0;
        $sum = 0;

        for ($k = $end - 1; $k >= $start; $k--) {
            $sum += $wordTokens[$k];
            if ($sum > $overlapTokens) {
                break;
            }
            $count++;
        }

        return min($count, $end - $start - 1);
    }

    public function name(): string
    {
        return 'fixed';
    }
}
