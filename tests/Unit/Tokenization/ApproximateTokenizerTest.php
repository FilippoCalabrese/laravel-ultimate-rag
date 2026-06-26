<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

beforeEach(fn () => $this->tokenizer = new ApproximateTokenizer);

it('counts zero tokens for empty/whitespace text', function () {
    expect($this->tokenizer->count(''))->toBe(0)
        ->and($this->tokenizer->count("   \n\t"))->toBe(0);
});

it('counts more tokens for longer text', function () {
    expect($this->tokenizer->count('one two three four five'))
        ->toBeGreaterThan($this->tokenizer->count('one two'));
});

it('truncates to at most the requested token budget', function () {
    $text = str_repeat('word ', 200);
    $truncated = $this->tokenizer->truncate($text, 10);

    expect($this->tokenizer->count($truncated))->toBeLessThanOrEqual(10)
        ->and(mb_strlen($truncated))->toBeLessThan(mb_strlen($text));
});

it('returns empty string for non-positive budget', function () {
    expect($this->tokenizer->truncate('hello world', 0))->toBe('');
});

it('leaves text within budget untouched', function () {
    expect($this->tokenizer->truncate('short', 100))->toBe('short');
});

it('reports its name', function () {
    expect($this->tokenizer->name())->toBe('approximate');
});
