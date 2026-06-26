<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;

/**
 * Splits a parsed document into indexable chunks (FR-CH-10, pluggable driver).
 */
interface Chunker
{
    /**
     * @param  array<string, mixed>  $options  Strategy-specific options (size, overlap...).
     * @return list<TextChunk>
     */
    public function chunk(ParsedDocument $document, array $options = []): array;

    public function name(): string;
}
