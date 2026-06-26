<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * EU-resident embedding provider (FR-EM-01): Mistral `mistral-embed`. Uses the
 * OpenAI-compatible `/embeddings` endpoint shape.
 */
final class MistralEmbedder extends HttpEmbedder
{
    protected function name(): string
    {
        return 'mistral';
    }

    protected function endpoint(): string
    {
        return '/embeddings';
    }

    protected function payload(array $texts): array
    {
        return ['model' => $this->model, 'input' => $texts];
    }

    protected function extractVectors($json): array
    {
        $data = is_array($json) ? ($json['data'] ?? []) : [];

        return array_map(
            static fn (array $row): array => array_values(array_map('floatval', $row['embedding'] ?? [])),
            array_values($data),
        );
    }

    protected function extractTokens($json, array $texts): int
    {
        if (is_array($json) && isset($json['usage']['total_tokens'])) {
            return (int) $json['usage']['total_tokens'];
        }

        return $this->estimateTokens($texts);
    }
}
