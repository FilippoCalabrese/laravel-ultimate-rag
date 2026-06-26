<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Sentence/paragraph-aware chunker (FR-CH-03): groups whole sentences into
 * chunks up to the target size, never splitting mid-sentence, with sentence-level
 * overlap for context continuity.
 */
final class SentenceChunker extends AbstractChunker
{
    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $size = max(1, (int) $this->option($options, 'size', 1000));
        $overlap = max(0, (int) $this->option($options, 'overlap', 1)); // overlap in sentences

        $sentences = $this->splitSentences($document->text);

        if ($sentences === []) {
            return [];
        }

        $chunks = [];
        $index = 0;
        $i = 0;
        $count = count($sentences);

        while ($i < $count) {
            $current = [];
            $j = $i;

            while ($j < $count) {
                $candidate = trim(implode(' ', [...$current, $sentences[$j]]));
                if ($current !== [] && mb_strlen($candidate) > $size) {
                    break;
                }
                $current[] = $sentences[$j];
                $j++;
            }

            $piece = trim(implode(' ', $current));
            // offset is a SENTENCE index, flagged via offset_unit (M2).
            $chunks[] = $this->makeChunk($piece, $index++, $i, $document->metadata, ['offset_unit' => 'sentence']);

            $advance = max(1, count($current) - $overlap);
            $i += $advance;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        // Split after ., !, ? followed by whitespace; keep the terminator.
        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map('trim', $parts);
    }

    public function name(): string
    {
        return 'sentence';
    }
}
