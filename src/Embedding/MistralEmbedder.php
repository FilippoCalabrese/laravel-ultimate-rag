<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

/**
 * EU-resident embedding provider (FR-EM-01): Mistral `mistral-embed`. Uses the
 * OpenAI-compatible `/embeddings` endpoint shape.
 */
final class MistralEmbedder extends OpenAiCompatibleEmbedder
{
    protected function name(): string
    {
        return 'mistral';
    }
}
