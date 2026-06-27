<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Evaluation;

/**
 * One labelled retrieval test case: a query and the ids that *should* be
 * retrieved for it. Relevant ids may be document ids or chunk ids — a hit
 * matches if either its chunk id or its document id is in the set.
 */
final class EvaluationCase
{
    /**
     * @param  list<string>  $relevant
     */
    public function __construct(
        public readonly string $query,
        public readonly array $relevant,
        public readonly ?string $note = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $relevant = $data['relevant'] ?? $data['relevant_ids'] ?? [];

        return new self(
            query: (string) ($data['query'] ?? ''),
            relevant: array_values(array_map('strval', is_array($relevant) ? $relevant : [$relevant])),
            note: isset($data['note']) ? (string) $data['note'] : null,
        );
    }
}
