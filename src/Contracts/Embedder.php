<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\EmbeddingResponse;

/**
 * Turns text into dense vectors (FR-EM). Implementations are EU-resident by
 * default; extra-UE providers are opt-in only.
 */
interface Embedder
{
    /**
     * Embed a batch of texts. Order of vectors matches order of inputs.
     *
     * @param  list<string>  $texts
     */
    public function embed(array $texts): EmbeddingResponse;

    /**
     * Embed a single text (convenience).
     */
    public function embedOne(string $text): EmbeddingResponse;

    public function dimensions(): int;

    public function model(): string;
}
