<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Chunking;

use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Recursive character chunker (FR-CH-02): splits on a hierarchy of separators
 * (paragraph → line → sentence → word → char), keeping semantically coherent
 * pieces, then merges adjacent pieces up to the target size with overlap.
 *
 * Offsets are tracked structurally (each atomic piece keeps its true source
 * position); a chunk's `offset` is the source position where its first piece
 * begins. Because pieces are re-joined with a single space, a chunk is not
 * byte-identical to the source slice, but its offset is a real anchor.
 */
final class RecursiveCharacterChunker extends AbstractChunker
{
    /** @var list<string> */
    private const SEPARATORS = ["\n\n", "\n", '. ', ' ', ''];

    public function chunk(ParsedDocument $document, array $options = []): array
    {
        $size = max(1, (int) $this->option($options, 'size', 1000));
        $overlap = max(0, min((int) $this->option($options, 'overlap', 200), $size - 1));

        $text = $document->text;

        if (trim($text) === '') {
            return [];
        }

        $pieces = $this->atomize($text, self::SEPARATORS, $size, 0);
        $merged = $this->merge($pieces, $size, $overlap);

        $chunks = [];
        foreach ($merged as $index => $piece) {
            $chunks[] = $this->makeChunk($piece['text'], $index, $piece['offset'], $document->metadata);
        }

        return $chunks;
    }

    /**
     * Break text into atomic pieces (each within $size) carrying their source
     * offset.
     *
     * @param  list<string>  $separators
     * @return list<array{text: string, offset: int}>
     */
    private function atomize(string $text, array $separators, int $size, int $baseOffset): array
    {
        if (mb_strlen($text) <= $size || $separators === []) {
            return trim($text) === '' ? [] : [['text' => $text, 'offset' => $baseOffset]];
        }

        $separator = $separators[0];
        $rest = array_slice($separators, 1);

        $result = [];

        if ($separator === '') {
            $position = 0;
            foreach (mb_str_split($text, max(1, $size)) ?: [] as $part) {
                if (trim($part) !== '') {
                    $result[] = ['text' => $part, 'offset' => $baseOffset + $position];
                }
                $position += mb_strlen($part);
            }

            return $result;
        }

        $position = 0;
        $sepLen = mb_strlen($separator);

        foreach (explode($separator, $text) as $part) {
            $offset = $baseOffset + $position;

            if ($part !== '') {
                if (mb_strlen($part) <= $size) {
                    if (trim($part) !== '') {
                        $result[] = ['text' => $part, 'offset' => $offset];
                    }
                } else {
                    $result = [...$result, ...$this->atomize($part, $rest, $size, $offset)];
                }
            }

            $position += mb_strlen($part) + $sepLen;
        }

        return $result;
    }

    /**
     * Merge atomic pieces into chunks up to $size with piece-level overlap. A
     * chunk never exceeds $size and its offset is its first piece's offset.
     *
     * @param  list<array{text: string, offset: int}>  $pieces
     * @return list<array{text: string, offset: int}>
     */
    private function merge(array $pieces, int $size, int $overlap): array
    {
        $chunks = [];
        /** @var list<array{text: string, offset: int}> $window */
        $window = [];
        $windowLen = 0;

        foreach ($pieces as $piece) {
            $addLen = ($window === [] ? 0 : 1) + mb_strlen($piece['text']);

            if ($window !== [] && $windowLen + $addLen > $size) {
                $chunks[] = $this->emit($window);
                $window = $this->overlapTail($window, $overlap);
                $windowLen = $this->joinedLength($window);
                $addLen = ($window === [] ? 0 : 1) + mb_strlen($piece['text']);
            }

            $window[] = $piece;
            $windowLen += $addLen;
        }

        if ($window !== []) {
            $chunks[] = $this->emit($window);
        }

        return $chunks;
    }

    /**
     * @param  list<array{text: string, offset: int}>  $window
     * @return array{text: string, offset: int}
     */
    private function emit(array $window): array
    {
        return [
            'text' => implode(' ', array_map(static fn (array $p): string => $p['text'], $window)),
            'offset' => $window[0]['offset'],
        ];
    }

    /**
     * Trailing pieces whose joined length stays within $overlap.
     *
     * @param  list<array{text: string, offset: int}>  $window
     * @return list<array{text: string, offset: int}>
     */
    private function overlapTail(array $window, int $overlap): array
    {
        if ($overlap <= 0) {
            return [];
        }

        $tail = [];
        $length = 0;

        foreach (array_reverse($window) as $piece) {
            $add = ($tail === [] ? 0 : 1) + mb_strlen($piece['text']);

            if ($length + $add > $overlap) {
                break;
            }

            array_unshift($tail, $piece);
            $length += $add;
        }

        return $tail;
    }

    /**
     * @param  list<array{text: string, offset: int}>  $window
     */
    private function joinedLength(array $window): int
    {
        if ($window === []) {
            return 0;
        }

        return array_sum(array_map(static fn (array $p): int => mb_strlen($p['text']), $window)) + (count($window) - 1);
    }

    public function name(): string
    {
        return 'recursive';
    }
}
