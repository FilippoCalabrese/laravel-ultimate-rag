<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Sellinnate\RagEngine\Retrieval\SearchBuilder;

/**
 * Fluent RAG question-answering builder (FR-DX-01/06). Wraps a
 * {@see SearchBuilder} for retrieval tuning and adds generation controls; the
 * terminal {@see generate()} runs the {@see RagGenerator}.
 */
final class AskBuilder
{
    private ?string $llm = null;

    private ?string $promptTemplate = null;

    private ?int $contextBudget = null;

    public function __construct(
        private readonly RagGenerator $generator,
        private readonly SearchBuilder $search,
    ) {}

    public function topK(int $topK): self
    {
        $this->search->topK($topK);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filter(array $filters): self
    {
        $this->search->filter($filters);

        return $this;
    }

    public function where(string $key, mixed $value): self
    {
        $this->search->where($key, $value);

        return $this;
    }

    public function hybrid(bool $hybrid = true): self
    {
        $this->search->hybrid($hybrid);

        return $this;
    }

    public function rerank(?string $reranker = null): self
    {
        $this->search->rerank($reranker);

        return $this;
    }

    public function expandParents(bool $expand = true): self
    {
        $this->search->expandParents($expand);

        return $this;
    }

    public function using(string $llm): self
    {
        $this->llm = $llm;

        return $this;
    }

    public function prompt(string $template): self
    {
        $this->promptTemplate = $template;

        return $this;
    }

    public function contextBudget(int $tokens): self
    {
        $this->contextBudget = $tokens;
        $this->search->contextBudget($tokens);

        return $this;
    }

    public function generate(): GenerationResult
    {
        return $this->generator->generate(
            $this->search->toRequest(),
            $this->llm,
            $this->promptTemplate,
            $this->contextBudget,
        );
    }
}
