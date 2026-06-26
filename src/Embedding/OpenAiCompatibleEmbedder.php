<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * Base for OpenAI-compatible embedding providers (OpenAI, Azure OpenAI, Mistral,
 * Jina, Voyage). Request: `{model, input: [...] , ...}` at `/embeddings`;
 * response: `{data: [{index, embedding}], usage: {total_tokens}}`.
 *
 * Providers add their own request params via {@see extraPayload()} and may change
 * the endpoint/auth.
 */
abstract class OpenAiCompatibleEmbedder extends HttpEmbedder
{
    protected function endpoint(): string
    {
        return '/embeddings';
    }

    protected function payload(array $texts): array
    {
        return ['model' => $this->model, 'input' => $texts, ...$this->extraPayload($texts)];
    }

    /**
     * Provider-specific request params (e.g. dimensions, input_type).
     *
     * @param  list<string>  $texts
     * @return array<string, mixed>
     */
    protected function extraPayload(array $texts): array
    {
        return [];
    }

    protected function extractVectors($json): array
    {
        $data = is_array($json) ? ($json['data'] ?? []) : [];

        // The API guarantees order via `index`; sort defensively before mapping.
        usort($data, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        return array_map(
            static fn (array $row): array => array_values(array_map('floatval', $row['embedding'] ?? [])),
            $data,
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
