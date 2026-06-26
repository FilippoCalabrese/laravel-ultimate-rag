<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Immutable result of parsing a source into normalized text plus structure.
 *
 * Implements FR-PA-10 (structure preservation) and FR-PA-11 (language) by
 * carrying logical sections and a detected language alongside the flat text.
 *
 * @implements Arrayable<string, mixed>
 */
final class ParsedDocument implements Arrayable
{
    /**
     * @param  list<DocumentSection>  $sections  Logical structure (headings, pages, tables).
     * @param  array<string, mixed>  $metadata  Native + caller metadata (author, title, dates...).
     */
    public function __construct(
        public readonly string $text,
        public readonly string $mimeType,
        public readonly array $sections = [],
        public readonly array $metadata = [],
        public readonly ?string $language = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->text,
            $this->mimeType,
            $this->sections,
            [...$this->metadata, ...$metadata],
            $this->language,
        );
    }

    public function withLanguage(?string $language): self
    {
        return new self($this->text, $this->mimeType, $this->sections, $this->metadata, $language);
    }

    public function withText(string $text): self
    {
        return new self($text, $this->mimeType, $this->sections, $this->metadata, $this->language);
    }

    /**
     * @param  list<DocumentSection>  $sections
     */
    public function withSections(array $sections): self
    {
        return new self($this->text, $this->mimeType, $sections, $this->metadata, $this->language);
    }

    /**
     * Replace (not merge) the metadata array.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function replaceMetadata(array $metadata): self
    {
        return new self($this->text, $this->mimeType, $this->sections, $metadata, $this->language);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'mime_type' => $this->mimeType,
            'sections' => array_map(fn (DocumentSection $s) => $s->toArray(), $this->sections),
            'metadata' => $this->metadata,
            'language' => $this->language,
        ];
    }
}
