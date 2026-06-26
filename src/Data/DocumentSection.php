<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A logical region of a parsed document (heading, paragraph, table, page).
 *
 * Supports FR-PA-09/FR-PA-10: tables and hierarchy are preserved rather than
 * flattened, so chunkers can respect structural boundaries.
 *
 * @implements Arrayable<string, mixed>
 */
final class DocumentSection implements Arrayable
{
    /**
     * @param  array<string, mixed>  $metadata  e.g. page number, table coordinates.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly int $level = 0,
        public readonly ?int $page = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'content' => $this->content,
            'level' => $this->level,
            'page' => $this->page,
            'metadata' => $this->metadata,
        ];
    }
}
