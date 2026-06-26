<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Sellinnate\RagEngine\Contracts\Llm;

/**
 * No-op LLM used when the optional generation layer is not configured
 * (FR-GE-05): its presence keeps the contract resolvable without pulling in any
 * LLM dependency.
 */
final class NullLlm implements Llm
{
    public function generate(string $prompt, array $options = []): string
    {
        return '';
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        return [];
    }

    public function model(): string
    {
        return 'null';
    }
}
