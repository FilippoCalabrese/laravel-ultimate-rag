<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Managers;

use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Tokenization\ApproximateTokenizer;

/**
 * Resolves tokenizers (decision 6.11).
 *
 * @extends DriverManager<Tokenizer>
 */
final class TokenizerManager extends DriverManager
{
    protected function configSection(): string
    {
        return 'tokenizers';
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->app->make('config')->get('rag-engine.defaults.tokenizer', 'approximate');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createApproximateDriver(array $config): Tokenizer
    {
        return new ApproximateTokenizer(
            charsPerToken: (int) ($config['chars_per_token'] ?? 4),
        );
    }
}
