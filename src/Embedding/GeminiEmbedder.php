<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Http\Client\PendingRequest;

/**
 * Google Gemini / Generative Language embeddings (FR-EM-03). Models:
 * `text-embedding-004`, `gemini-embedding-001`. Batch endpoint
 * `models/{model}:batchEmbedContents`; auth via the `x-goog-api-key` header.
 * The model carries no token usage, so usage is estimated.
 */
final class GeminiEmbedder extends HttpEmbedder
{
    protected function name(): string
    {
        return 'gemini';
    }

    protected function endpoint(): string
    {
        return "/v1beta/models/{$this->model}:batchEmbedContents";
    }

    protected function payload(array $texts): array
    {
        $modelRef = 'models/'.$this->model;
        $outputDim = $this->option('output_dimensionality', true) !== false ? $this->dimensions : null;

        return [
            'requests' => array_map(function (string $text) use ($modelRef, $outputDim): array {
                $request = [
                    'model' => $modelRef,
                    'content' => ['parts' => [['text' => $text]]],
                ];

                if ($outputDim !== null) {
                    $request['outputDimensionality'] = $outputDim;
                }

                if (is_string($this->option('task_type'))) {
                    $request['taskType'] = $this->option('task_type');
                }

                return $request;
            }, $texts),
        ];
    }

    protected function extractVectors($json): array
    {
        $embeddings = is_array($json) ? ($json['embeddings'] ?? []) : [];

        return array_map(
            static fn (array $row): array => array_values(array_map('floatval', $row['values'] ?? [])),
            array_values($embeddings),
        );
    }

    protected function extractTokens($json, array $texts): int
    {
        return $this->estimateTokens($texts);
    }

    protected function applyAuth(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders(['x-goog-api-key' => (string) $this->apiKey]);
    }
}
