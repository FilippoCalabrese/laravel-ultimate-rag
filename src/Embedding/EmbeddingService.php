<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Observability\UsageRecorder;

/**
 * Orchestrates embedding: resolves the (cached, retrying) provider, embeds in
 * batches (FR-EM-04), and records token/cost usage per tenant (FR-EM-07). The
 * model + dimensions on the response give document-level versioning (FR-EM-08).
 */
final class EmbeddingService
{
    public function __construct(
        private readonly EmbedderManager $embedders,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * @param  list<string>  $texts
     */
    public function embed(array $texts, ?string $provider = null, int $batchSize = 96): EmbeddingResponse
    {
        $embedder = $this->embedders->driver($provider);

        if ($texts === []) {
            return new EmbeddingResponse([], $embedder->model(), $embedder->dimensions(), Usage::zero());
        }

        $vectors = [];
        $usage = Usage::zero();

        foreach (array_chunk($texts, max(1, $batchSize)) as $batch) {
            $response = $embedder->embed($batch);
            $vectors = [...$vectors, ...$response->vectors];
            $usage = $usage->plus($response->usage);
        }

        $response = new EmbeddingResponse($vectors, $embedder->model(), $embedder->dimensions(), $usage);

        if ($usage->tokens > 0 || $usage->cost > 0.0) {
            $this->usage->record('embedding', $usage, [
                'model' => $response->model,
                'dimensions' => $response->dimensions,
                'count' => count($texts),
            ]);
        }

        return $response;
    }
}
