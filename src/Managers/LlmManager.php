<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Illuminate\Http\Client\Factory as HttpFactory;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Generation\AnthropicLlm;
use Sellinnate\RagEngine\Generation\FakeLlm;
use Sellinnate\RagEngine\Generation\NullLlm;
use Sellinnate\RagEngine\Generation\OpenAiLlm;

/**
 * Resolves optional LLM backends (FR-GE-02). Default is the no-op driver so the
 * generation layer never forces an LLM dependency (FR-GE-05). Ships real drivers
 * for Anthropic (Claude) and any OpenAI-compatible chat API.
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

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAnthropicDriver(array $config): Llm
    {
        return new AnthropicLlm(
            $this->app->make(HttpFactory::class),
            (string) ($config['model'] ?? 'claude-sonnet-4-6'),
            isset($config['api_key']) ? (string) $config['api_key'] : null,
            (string) ($config['base_url'] ?? 'https://api.anthropic.com'),
            $config,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createOpenaiDriver(array $config): Llm
    {
        return new OpenAiLlm(
            $this->app->make(HttpFactory::class),
            (string) ($config['model'] ?? 'gpt-4o-mini'),
            isset($config['api_key']) ? (string) $config['api_key'] : null,
            (string) ($config['base_url'] ?? 'https://api.openai.com/v1'),
            $config,
        );
    }
}
