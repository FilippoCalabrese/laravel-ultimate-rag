<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Jina AI embeddings (FR-EM-01) — EU-resident provider. `jina-embeddings-v3`
 * supports Matryoshka `dimensions` and a `task` (e.g. retrieval.query /
 * retrieval.passage). OpenAI-compatible request/response shape.
 */
final class JinaEmbedder extends OpenAiCompatibleEmbedder
{
    protected function name(): string
    {
        return 'jina';
    }

    protected function extraPayload(array $texts): array
    {
        $extra = [];

        if (str_contains($this->model, 'v3')) {
            $extra['dimensions'] = $this->dimensions;
        }

        foreach (['task', 'late_chunking'] as $key) {
            if ($this->option($key) !== null) {
                $extra[$key] = $this->option($key);
            }
        }

        return $extra;
    }
}
