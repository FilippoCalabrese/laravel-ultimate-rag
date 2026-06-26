<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Http\Client\PendingRequest;

/**
 * OpenAI embeddings (FR-EM-03): `text-embedding-3-small`, `text-embedding-3-large`
 * and `text-embedding-ada-002`. Extra-EU provider — opt-in only.
 *
 * The v3 models support a `dimensions` parameter to shorten the output vector;
 * it is sent automatically for `text-embedding-3-*` models.
 */
final class OpenAIEmbedder extends OpenAiCompatibleEmbedder
{
    protected function name(): string
    {
        return 'openai';
    }

    protected function extraPayload(array $texts): array
    {
        $extra = [];

        // ada-002 rejects `dimensions`; v3 models accept it to truncate output.
        if (str_starts_with($this->model, 'text-embedding-3')) {
            $extra['dimensions'] = $this->dimensions;
        }

        if (is_string($this->option('encoding_format'))) {
            $extra['encoding_format'] = $this->option('encoding_format');
        }

        return $extra;
    }

    protected function applyAuth(PendingRequest $request): PendingRequest
    {
        $request = parent::applyAuth($request);

        // Optional organization / project scoping headers.
        $headers = array_filter([
            'OpenAI-Organization' => $this->option('organization'),
            'OpenAI-Project' => $this->option('project'),
        ], static fn ($v): bool => is_string($v) && $v !== '');

        return $headers === [] ? $request : $request->withHeaders($headers);
    }
}
