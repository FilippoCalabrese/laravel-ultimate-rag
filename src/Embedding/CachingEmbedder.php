<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Contracts\Cache\Repository as Cache;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;

/**
 * Caching decorator (FR-EM-05, NFR-PE-04). The cache key is a hash of
 * text + model + dimensions, so identical text is never re-embedded — saving
 * tokens and cost. Only cache-missed texts hit the underlying provider, and
 * usage reflects only those (cache hits cost nothing).
 */
final class CachingEmbedder implements Embedder
{
    public function __construct(
        private readonly Embedder $inner,
        private readonly Cache $cache,
        private readonly int $ttl = 2592000, // 30 days
        private readonly string $prefix = 'rag:emb:',
        private readonly ?string $identity = null,
    ) {}

    public function embed(array $texts): EmbeddingResponse
    {
        /** @var array<int, list<float>> $vectors */
        $vectors = [];
        /** @var array<int, string> $missPositions */
        $missPositions = [];
        /** @var array<string, true> $uniqueMisses */
        $uniqueMisses = [];

        foreach ($texts as $i => $text) {
            $cached = $this->cache->get($this->key($text));

            if (is_array($cached)) {
                $vectors[$i] = array_values(array_map('floatval', $cached));
            } else {
                $missPositions[$i] = $text;
                $uniqueMisses[$text] = true;
            }
        }

        $usage = Usage::zero();

        if ($uniqueMisses !== []) {
            // Embed each distinct missing text exactly once (no double-charging
            // duplicates within a batch), then fan results out to all positions.
            $missTexts = array_keys($uniqueMisses);
            $response = $this->inner->embed($missTexts);
            $usage = $response->usage;

            $byText = [];
            foreach ($missTexts as $n => $text) {
                $vector = $response->vectorAt($n);
                $byText[$text] = $vector;
                $this->cache->put($this->key($text), $vector, $this->ttl);
            }

            foreach ($missPositions as $position => $text) {
                $vectors[$position] = $byText[$text];
            }
        }

        ksort($vectors);

        return new EmbeddingResponse(array_values($vectors), $this->model(), $this->dimensions(), $usage);
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    private function key(string $text): string
    {
        // Provider identity is part of the key so two providers sharing a model
        // name + dimensions never poison each other's cache (different vectors).
        $identity = $this->identity ?? $this->inner::class;

        return $this->prefix.hash('sha256', $identity.':'.$this->model().':'.$this->dimensions().':'.$text);
    }
}
