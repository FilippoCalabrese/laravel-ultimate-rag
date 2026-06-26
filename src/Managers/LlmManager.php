<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Generation\FakeLlm;
use Sellinnate\RagEngine\Generation\NullLlm;

/**
 * Resolves optional LLM backends (FR-GE-02). Default is the no-op driver so the
 * generation layer never forces an LLM dependency (FR-GE-05).
 *
 * @extends DriverManager<Llm>
 */
final class LlmManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'llms';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.llm', 'null');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createNullDriver(array $config): Llm
    {
        return new NullLlm;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createFakeDriver(array $config): Llm
    {
        return new FakeLlm;
    }
}
