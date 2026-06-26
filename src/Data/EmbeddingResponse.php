<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Countable;
use InvalidArgumentException;

/**
 * Result of an embedding batch: one float vector per input text plus usage.
 *
 * Enforces FR-EM-10 dimensional consistency: every vector in the batch must
 * share the declared dimensionality.
 */
final class EmbeddingResponse implements Countable
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public readonly array $vectors,
        public readonly string $model,
        public readonly int $dimensions,
        public readonly Usage $usage,
    ) {
        foreach ($vectors as $i => $vector) {
            if (count($vector) !== $dimensions) {
                throw new InvalidArgumentException(
                    "Embedding at index {$i} has ".count($vector)." dimensions, expected {$dimensions}."
                );
            }
        }
    }

    /**
     * @return list<float>
     */
    public function vectorAt(int $index): array
    {
        if (! array_key_exists($index, $this->vectors)) {
            throw new InvalidArgumentException("No embedding at index {$index}.");
        }

        return $this->vectors[$index];
    }

    public function count(): int
    {
        return count($this->vectors);
    }
}
