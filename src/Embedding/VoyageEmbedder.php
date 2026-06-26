<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Voyage AI embeddings (FR-EM-03) — retrieval-optimised, extra-EU (opt-in).
 * Models: `voyage-3`, `voyage-3-lite`, `voyage-large-2`, etc. Supports an
 * `input_type` (`query` / `document`) and `output_dimension`. OpenAI-compatible
 * response shape.
 */
final class VoyageEmbedder extends OpenAiCompatibleEmbedder
{
    protected function name(): string
    {
        return 'voyage';
    }

    protected function extraPayload(array $texts): array
    {
        $extra = [];

        if ($this->option('input_type') !== null) {
            $extra['input_type'] = $this->option('input_type');
        }

        if ($this->option('output_dimension', true) !== false) {
            $extra['output_dimension'] = $this->dimensions;
        }

        return $extra;
    }
}
