<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Http\Client\PendingRequest;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Azure OpenAI embeddings (FR-EM-01, decision 6.5) — an EU-resident option when
 * the resource is provisioned in an EU region. Same body shape as OpenAI but a
 * deployment-based URL and `api-key` header auth.
 *
 * Required options: `deployment` (the Azure deployment name) and `api_version`.
 * `base_url` is the resource endpoint, e.g. https://my-res.openai.azure.com
 */
final class AzureOpenAIEmbedder extends OpenAiCompatibleEmbedder
{
    protected function name(): string
    {
        return 'azure-openai';
    }

    protected function endpoint(): string
    {
        $deployment = $this->option('deployment') ?? $this->model;
        $apiVersion = $this->option('api_version');

        if (! is_string($deployment) || $deployment === '' || ! is_string($apiVersion) || $apiVersion === '') {
            throw new RagException('Azure OpenAI embedder requires `deployment` and `api_version` options.');
        }

        return "/openai/deployments/{$deployment}/embeddings?api-version={$apiVersion}";
    }

    protected function payload(array $texts): array
    {
        // Azure does not accept the `model` field (the deployment is in the URL).
        return ['input' => $texts, ...$this->extraPayload($texts)];
    }

    protected function extraPayload(array $texts): array
    {
        // v3 deployments accept `dimensions`; disable for ada deployments.
        return $this->option('send_dimensions', true) !== false
            ? ['dimensions' => $this->dimensions]
            : [];
    }

    protected function applyAuth(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders(['api-key' => (string) $this->apiKey]);
    }
}
