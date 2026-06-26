<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Preprocessing;

use Sellinnate\RagEngine\Contracts\PreprocessingStage;
use Sellinnate\RagEngine\Data\ParsedDocument;

/**
 * Composable preprocessing pipeline (FR-PP-04): an ordered, swappable list of
 * stages run over a parsed document. Stages are configured/activated by the
 * service provider from `rag-engine.preprocessing`.
 */
final class PreprocessingPipeline
{
    /** @var list<PreprocessingStage> */
    private array $stages;

    /**
     * @param  list<PreprocessingStage>  $stages
     */
    public function __construct(array $stages = [])
    {
        $this->stages = $stages;
    }

    public function pipe(PreprocessingStage $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }

    public function process(ParsedDocument $document): ParsedDocument
    {
        foreach ($this->stages as $stage) {
            $document = $stage->process($document);
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    public function stageNames(): array
    {
        return array_map(static fn (PreprocessingStage $s): string => $s->name(), $this->stages);
    }
}
