<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

/**
 * Optional generation backend (FR-GE-02). The whole generation layer is
 * isolated (FR-GE-05): its absence never impacts ingestion/retrieval.
 */
interface Llm
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function generate(string $prompt, array $options = []): string;

    /**
     * Stream the response token-by-token (FR-GE-04).
     *
     * @param  array<string, mixed>  $options
     * @return iterable<string>
     */
    public function stream(string $prompt, array $options = []): iterable;

    public function model(): string;
}
