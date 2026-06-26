<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Self-hosted embedding provider (FR-EM-02): Ollama (BGE/E5/Nomic). Maximum
 * data sovereignty, zero per-token cost. Uses the `/api/embed` batch endpoint.
 */
final class OllamaEmbedder extends HttpEmbedder
{
    protected function name(): string
    {
        return 'ollama';
    }

    protected function endpoint(): string
    {
        return '/api/embed';
    }

    protected function payload(array $texts): array
    {
        return ['model' => $this->model, 'input' => $texts];
    }

    protected function extractVectors($json): array
    {
        $embeddings = is_array($json) ? ($json['embeddings'] ?? []) : [];

        return array_map(
            static fn (array $vector): array => array_values(array_map('floatval', $vector)),
            array_values($embeddings),
        );
    }

    protected function extractTokens($json, array $texts): int
    {
        if (is_array($json) && isset($json['prompt_eval_count'])) {
            return (int) $json['prompt_eval_count'];
        }

        return $this->estimateTokens($texts);
    }
}
