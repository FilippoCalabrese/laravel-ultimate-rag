<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Hugging Face Inference API embeddings (FR-EM-02 spirit: open models, self-host
 * possible). Targets the feature-extraction pipeline (sentence-transformers:
 * BGE / E5 / GTE / Nomic …), returning a sentence embedding per input. Bearer
 * auth; no token usage reported, so usage is estimated.
 */
final class HuggingFaceEmbedder extends HttpEmbedder
{
    protected function name(): string
    {
        return 'huggingface';
    }

    protected function endpoint(): string
    {
        return "/pipeline/feature-extraction/{$this->model}";
    }

    protected function payload(array $texts): array
    {
        return [
            'inputs' => $texts,
            'options' => ['wait_for_model' => (bool) $this->option('wait_for_model', true)],
        ];
    }

    protected function extractVectors($json): array
    {
        if (! is_array($json)) {
            return [];
        }

        return array_map(
            static fn (array $vector): array => array_values(array_map('floatval', $vector)),
            array_values($json),
        );
    }

    protected function extractTokens($json, array $texts): int
    {
        return $this->estimateTokens($texts);
    }
}
