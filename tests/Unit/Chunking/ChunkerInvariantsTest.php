<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Chunking\FixedSizeChunker;
use Sellinnate\RagEngine\Chunking\RecursiveCharacterChunker;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

beforeEach(fn () => $this->tok = new ApproximateTokenizer);

it('FixedSizeChunker char offsets point at the real source slice (C1/M2)', function () {
    $text = str_repeat('The quick brown fox. ', 30);
    $chunks = (new FixedSizeChunker($this->tok))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 50, 'overlap' => 10]);

    foreach ($chunks as $chunk) {
        expect(mb_substr($text, $chunk->offset, mb_strlen($chunk->content)))->toBe($chunk->content)
            ->and($chunk->metadata['offset_unit'])->toBe('char');
    }
});

it('RecursiveCharacterChunker offsets are monotonic, in-bounds, and anchor the first word (C1)', function () {
    $text = "SECTION zero filler.\n\nSECTION one body here.\n\nSECTION two ending text.";
    $chunks = (new RecursiveCharacterChunker($this->tok))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 30, 'overlap' => 8]);

    $len = mb_strlen($text);
    $previous = -1;

    foreach ($chunks as $chunk) {
        expect($chunk->offset)->toBeGreaterThanOrEqual(0)->toBeLessThan($len)
            ->and($chunk->offset)->toBeGreaterThan($previous); // strictly increasing
        $previous = $chunk->offset;

        // The first word of the chunk appears in the source at/after the offset.
        $firstWord = preg_split('/\s+/u', trim($chunk->content))[0];
        expect(mb_strpos($text, $firstWord, $chunk->offset))->toBe($chunk->offset);
    }
});

it('RecursiveCharacterChunker never emits a chunk over the size budget (M3)', function () {
    $text = str_repeat('word ', 200);
    $chunks = (new RecursiveCharacterChunker($this->tok))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 25, 'overlap' => 20]);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(25);
    }
});

it('token-aware chunking scales linearly enough (M1 perf)', function () {
    $text = implode(' ', array_fill(0, 8000, 'word'));

    $start = hrtime(true);
    $chunks = (new FixedSizeChunker($this->tok))->chunk(new ParsedDocument($text, 'text/plain'), ['size' => 500, 'overlap' => 50, 'unit' => 'tokens']);
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($elapsedMs)->toBeLessThan(500); // was multi-second with O(n^2) re-tokenization
});
