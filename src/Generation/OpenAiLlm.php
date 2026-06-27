<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Illuminate\Http\Client\PendingRequest;

/**
 * OpenAI-compatible chat-completions generation backend (FR-GE-02).
 *
 * Works with OpenAI and any OpenAI-compatible API by pointing `base_url` at it
 * (Mistral, Ollama's `/v1`, Groq, Together, OpenRouter, Azure-style gateways...).
 */
final class OpenAiLlm extends HttpLlm
{
    protected function name(): string
    {
        return 'openai';
    }

    protected function endpoint(): string
    {
        return '/chat/completions';
    }

    protected function payload(string $prompt): array
    {
        $messages = [];

        if (is_string($this->option('system'))) {
            $messages[] = ['role' => 'system', 'content' => $this->option('system')];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if ($this->option('max_tokens') !== null) {
            $payload['max_tokens'] = (int) $this->option('max_tokens');
        }

        if (is_numeric($this->option('temperature'))) {
            $payload['temperature'] = (float) $this->option('temperature');
        }

        return $payload;
    }

    protected function extractText(mixed $json): string
    {
        if (! is_array($json)) {
            return '';
        }

        return (string) ($json['choices'][0]['message']['content'] ?? '');
    }

    protected function applyAuth(PendingRequest $request): PendingRequest
    {
        $request = $request->withToken((string) $this->apiKey);

        if (is_string($this->option('organization'))) {
            $request = $request->withHeaders(['OpenAI-Organization' => $this->option('organization')]);
        }

        return $request;
    }

    protected function parseStreamLine(string $line): ?string
    {
        if (! str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, 5));
        if ($data === '' || $data === '[DONE]') {
            return null;
        }

        $json = json_decode($data, true);
        if (! is_array($json)) {
            return null;
        }

        $text = (string) ($json['choices'][0]['delta']['content'] ?? '');

        return $text === '' ? null : $text;
    }
}
