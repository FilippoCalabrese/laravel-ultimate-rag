<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Eloquent;

use Sellinnate\RagEngine\Contracts\Embeddable;

/**
 * Fluent declaration of what a model contributes to its embedding (FR-DX-05).
 *
 * Built inside {@see Embeddable::toEmbeddable()}. Holds the ordered text parts,
 * the related embeddables to compose recursively, the provenance metadata and
 * optional overrides (document key, chunking options). Empty / blank values are
 * silently dropped so a model never indexes whitespace.
 */
final class EmbeddableDefinition
{
    /** @var list<array{label: string, value: string}> */
    private array $parts = [];

    /** @var list<array{relation: string, embeddable: Embeddable}> */
    private array $included = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    private ?string $documentKey = null;

    /** @var array<string, mixed> */
    private array $options = [];

    public static function make(): self
    {
        return new self;
    }

    /**
     * Add a labelled text part (e.g. "Title" => $this->title). Null/blank values
     * are ignored. Scalars are stringified.
     */
    public function add(string $label, string|int|float|bool|null $value): self
    {
        $text = $this->stringify($value);

        if ($text !== '') {
            $this->parts[] = ['label' => $label, 'value' => $text];
        }

        return $this;
    }

    /**
     * Add an unlabelled block of text.
     */
    public function text(string|int|float|bool|null $value): self
    {
        return $this->add('', $value);
    }

    /**
     * Compose a single related embeddable into this one (recursive embedding).
     * Nulls are ignored, so `->include($this->author)` is safe when the relation
     * is absent.
     */
    public function include(?Embeddable $embeddable, ?string $as = null): self
    {
        if ($embeddable instanceof Embeddable) {
            $this->included[] = ['relation' => $as ?? '', 'embeddable' => $embeddable];
        }

        return $this;
    }

    /**
     * Compose a collection of related embeddables (e.g. a hasMany relation).
     *
     * @param  iterable<mixed>  $embeddables
     */
    public function includeMany(iterable $embeddables, ?string $as = null): self
    {
        foreach ($embeddables as $embeddable) {
            if ($embeddable instanceof Embeddable) {
                $this->include($embeddable, $as);
            }
        }

        return $this;
    }

    /**
     * Attach arbitrary provenance metadata to the indexed document.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = [...$this->metadata, ...$metadata];

        return $this;
    }

    /**
     * Override the logical key used to group/supersede versions of this model's
     * document. Defaults to the model's stable `type:id` identity.
     */
    public function documentKey(string $key): self
    {
        $this->documentKey = $key;

        return $this;
    }

    /**
     * Per-model chunking/indexing options forwarded to the pipeline.
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): self
    {
        $this->options = [...$this->options, ...$options];

        return $this;
    }

    /** @return list<array{label: string, value: string}> */
    public function parts(): array
    {
        return $this->parts;
    }

    /** @return list<array{relation: string, embeddable: Embeddable}> */
    public function included(): array
    {
        return $this->included;
    }

    /** @return array<string, mixed> */
    public function metadataArray(): array
    {
        return $this->metadata;
    }

    public function documentKeyOverride(): ?string
    {
        return $this->documentKey;
    }

    /** @return array<string, mixed> */
    public function optionsArray(): array
    {
        return $this->options;
    }

    /**
     * True when this definition has neither own text nor included embeddables.
     */
    public function isEmpty(): bool
    {
        return $this->parts === [] && $this->included === [];
    }

    private function stringify(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
