<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Contracts;

use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * A composable preprocessing step (FR-PP-04). Stages transform a
 * {@see ParsedDocument} — cleaning text, normalizing, redacting PII — and can be
 * activated/ordered from config.
 */
interface PreprocessingStage
{
    public function process(ParsedDocument $document): ParsedDocument;

    public function name(): string;
}
