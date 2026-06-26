<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;

/**
 * Deterministic, zero-network embedder (NFR-TE-01, NFR-TE-02).
 *
 * The vector is a function of the input text only, so identical text always
 * yields identical vectors — which lets cache and idempotency tests assert real
 * behaviour, and gives meaningful (if synthetic) cosine similarity.
 */
final class FakeEmbedder implements Embedder
{
    public function __construct(
        private readonly int $dimensions = 8,
        private readonly string $model = 'fake-embed-v1',
        private readonly ?Tokenizer $tokenizer = null,
    ) {}

    public function embed(array $texts): EmbeddingResponse
    {
        $vectors = array_map(fn (string $text): array => $this->vectorFor($text), $texts);

        $tokens = array_sum(array_map(
            fn (string $text): int => $this->tokenizer?->count($text) ?? (int) ceil(mb_strlen($text) / 4),
            $texts,
        ));

        return new EmbeddingResponse(
            vectors: $vectors,
            model: $this->model,
            dimensions: $this->dimensions,
            usage: new Usage(tokens: (int) $tokens, cost: 0.0),
        );
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return list<float> Unit-normalized deterministic vector.
     */
    private function vectorFor(string $text): array
    {
        $vector = [];

        for ($i = 0; $i < $this->dimensions; $i++) {
            $hash = hash('sha256', $this->model.':'.$i.':'.$text);
            $intval = hexdec(substr($hash, 0, 8)); // 0 .. 2^32-1
            $vector[] = ($intval / 0xFFFFFFFF) * 2 - 1; // map to [-1, 1]
        }

        return $this->normalize($vector);
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }
}
