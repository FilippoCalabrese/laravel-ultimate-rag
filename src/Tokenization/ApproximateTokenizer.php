<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Tokenization;

use Sellinnate\RagEngine\Contracts\Tokenizer;

/**
 * Provider-agnostic heuristic tokenizer (decision 6.11). Estimates token count
 * from word and character counts — good enough for chunk budgeting and a priori
 * cost estimation when a model-specific BPE tokenizer is not available.
 */
final class ApproximateTokenizer implements Tokenizer
{
    public function __construct(
        private readonly int $charsPerToken = 4,
    ) {}

    public function count(string $text): int
    {
        $text = trim($text);

        if ($text === '') {
            return 0;
        }

        // Blend a word-based and a character-based estimate; real BPE token
        // counts sit between "1 token per word" and "1 token per ~4 chars".
        $words = preg_split('/\s+/u', $text) ?: [];
        $wordEstimate = (int) ceil(count($words) * 1.3);
        $charEstimate = (int) ceil(mb_strlen($text) / max(1, $this->charsPerToken));

        return max(1, (int) round(($wordEstimate + $charEstimate) / 2));
    }

    public function truncate(string $text, int $maxTokens): string
    {
        if ($maxTokens <= 0) {
            return '';
        }

        if ($this->count($text) <= $maxTokens) {
            return $text;
        }

        // Trim by character budget, then walk back to the token limit.
        $approxChars = $maxTokens * $this->charsPerToken;
        $candidate = mb_substr($text, 0, $approxChars);

        while ($candidate !== '' && $this->count($candidate) > $maxTokens) {
            $candidate = mb_substr($candidate, 0, mb_strlen($candidate) - max(1, $this->charsPerToken));
        }

        return rtrim($candidate);
    }

    public function name(): string
    {
        return 'approximate';
    }
}
