<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Illuminate\Contracts\Config\Repository as Config;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Retrieval\Retriever;
use Sellinnate\RagEngine\Retrieval\SearchRequest;

/**
 * Optional RAG generation (FR-GE): retrieve → assemble cited context → prompt
 * the LLM → return the answer with source attribution. Fully isolated — the
 * absence of an LLM never impacts ingestion/retrieval (FR-GE-05).
 */
final class RagGenerator
{
    public function __construct(
        private readonly Retriever $retriever,
        private readonly ContextAssembler $assembler,
        private readonly LlmManager $llms,
        private readonly Config $config,
    ) {}

    public function generate(
        SearchRequest $request,
        ?string $llm = null,
        ?string $promptTemplate = null,
        ?int $contextBudget = null,
    ): GenerationResult {
        $hits = $this->retriever->retrieve($request);

        $budget = $contextBudget
            ?? $request->contextBudgetTokens
            ?? (int) $this->config->get('rag-engine.generation.context_budget_tokens', 2000);

        $assembled = $this->assembler->assemble($hits, $budget);

        $template = $promptTemplate ?? (string) $this->config->get('rag-engine.generation.prompt_template', '{context}\n\n{question}');
        $prompt = strtr($template, ['{context}' => $assembled['context'], '{question}' => $request->text]);

        $answer = $this->llms->driver($llm)->generate($prompt);

        return new GenerationResult($answer, $assembled['citations'], $hits);
    }
}
