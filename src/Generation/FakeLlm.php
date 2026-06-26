<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Sellinnate\RagEngine\Contracts\Llm;

/**
 * Deterministic LLM for tests (NFR-TE-01): echoes a summary of the prompt so
 * generation flows can be asserted without a network call.
 */
final class FakeLlm implements Llm
{
    public function generate(string $prompt, array $options = []): string
    {
        return 'ANSWER: '.mb_substr(trim($prompt), 0, 80);
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        foreach (explode(' ', $this->generate($prompt, $options)) as $word) {
            yield $word.' ';
        }
    }

    public function model(): string
    {
        return 'fake-llm';
    }
}
