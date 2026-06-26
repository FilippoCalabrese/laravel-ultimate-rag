<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Cohere embeddings (FR-EM-03) — multilingual, extra-EU (opt-in). Uses the v2
 * `/v2/embed` API whose shape differs from OpenAI: `texts` + a REQUIRED
 * `input_type` (`search_document` / `search_query` / `classification` /
 * `clustering`) and `embedding_types`. Response: `embeddings.float[][]`.
 */
final class CohereEmbedder extends HttpEmbedder
{
    protected function name(): string
    {
        return 'cohere';
    }

    protected function endpoint(): string
    {
        return '/v2/embed';
    }

    protected function payload(array $texts): array
    {
        $payload = [
            'model' => $this->model,
            'texts' => $texts,
            'input_type' => (string) $this->option('input_type', 'search_document'),
            'embedding_types' => ['float'],
        ];

        if (is_int($this->option('output_dimension'))) {
            $payload['output_dimension'] = $this->option('output_dimension');
        }

        return $payload;
    }

    protected function extractVectors($json): array
    {
        $embeddings = is_array($json) ? ($json['embeddings'] ?? []) : [];
        // v2 nests under `float`; v1 returned a bare list.
        $rows = $embeddings['float'] ?? $embeddings;

        return array_map(
            static fn (array $vector): array => array_values(array_map('floatval', $vector)),
            array_values(is_array($rows) ? $rows : []),
        );
    }

    protected function extractTokens($json, array $texts): int
    {
        if (is_array($json) && isset($json['meta']['billed_units']['input_tokens'])) {
            return (int) $json['meta']['billed_units']['input_tokens'];
        }

        return $this->estimateTokens($texts);
    }
}
