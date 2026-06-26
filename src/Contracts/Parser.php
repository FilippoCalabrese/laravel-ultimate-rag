<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Extracts normalized text + structure from a raw source (FR-PA-13).
 *
 * Parsers are registrable drivers: new formats are isolated additions that do
 * not touch the rest of the pipeline.
 */
interface Parser
{
    /**
     * Whether this parser can handle the given MIME type / extension.
     */
    public function supports(string $mimeType): bool;

    /**
     * Parse raw bytes into a {@see ParsedDocument}. Implementations must defend
     * against malicious input (XXE, zip-bombs, path traversal) per FR-SEC-08.
     *
     * @param  array<string, mixed>  $context  e.g. filename, source url.
     */
    public function parse(string $contents, string $mimeType, array $context = []): ParsedDocument;

    /**
     * @return list<string> MIME types this parser advertises support for.
     */
    public function mimeTypes(): array;
}
