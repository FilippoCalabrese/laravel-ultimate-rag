<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;

/**
 * Marks an Eloquent model as embeddable into the RAG engine (FR-DX-05).
 *
 * The contract carries a single responsibility: declare *what* of the model is
 * embedded. The model builds an {@see EmbeddableDefinition} — an ordered set of
 * text parts, related embeddables (recursive embedding) and provenance metadata.
 * The engine handles the rest (compose → ingest → chunk → embed → index) and can
 * always trace an indexed vector back to the originating model.
 *
 * A related model returned via {@see EmbeddableDefinition::include()} /
 * {@see EmbeddableDefinition::includeMany()} only needs to implement this same
 * contract — its definition is composed into the parent's, recursively and
 * cycle-safe.
 */
interface Embeddable
{
    /**
     * Declare what this model contributes to its embedding. This is the single
     * source of truth for the embedded representation: fields, related
     * embeddables and metadata all live in the returned definition.
     */
    public function toEmbeddable(): EmbeddableDefinition;
}
