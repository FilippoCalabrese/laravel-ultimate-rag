<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

/**
 * Expands or rewrites a query before retrieval (FR-QT): multi-query, HyDE,
 * step-back. May require an LLM (optional dependency).
 */
interface QueryTransformer
{
    /**
     * @return list<string> One or more queries to run and union.
     */
    public function transform(string $query): array;

    public function name(): string;
}
