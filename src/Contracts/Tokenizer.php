<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

/**
 * Token counting abstraction (decision 6.11). Chunk boundaries, context budget
 * and cost estimation all depend on the target model's tokenization.
 */
interface Tokenizer
{
    public function count(string $text): int;

    /**
     * Truncate text to at most $maxTokens tokens (best-effort, non-destructive
     * of earlier content).
     */
    public function truncate(string $text, int $maxTokens): string;

    public function name(): string;
}
