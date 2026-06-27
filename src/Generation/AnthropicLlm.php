<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Illuminate\Http\Client\PendingRequest;

/**
 * Anthropic Claude generation backend (FR-GE-02) via the Messages API.
 *
 * Models: `claude-opus-4-8`, `claude-sonnet-4-6`, `claude-haiku-4-5-...`.
 * Auth uses the `x-api-key` + `anthropic-version` headers. Anthropic offers no
 * embedding API, so this is a generation-only (LLM) driver.
 */
final class AnthropicLlm extends HttpLlm
{
    protected function name(): string
    {
        return 'anthropic';
    }

    protected function endpoint(): string
    {
        return '/v1/messages';
    }

    protected function payload(string $prompt): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => (int) $this->option('max_tokens', 1024),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        if (is_string($this->option('system'))) {
            $payload['system'] = $this->option('system');
        }

        if (is_numeric($this->option('temperature'))) {
            $payload['temperature'] = (float) $this->option('temperature');
        }

        return $payload;
    }

    protected function extractText(mixed $json): string
    {
        if (! is_array($json) || ! isset($json['content']) || ! is_array($json['content'])) {
            return '';
        }

        $text = '';
        foreach ($json['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        return $text;
    }

    protected function applyAuth(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders([
            'x-api-key' => (string) $this->apiKey,
            'anthropic-version' => (string) $this->option('version', '2023-06-01'),
        ]);
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

        if (($json['type'] ?? null) === 'content_block_delta'
            && is_array($json['delta'] ?? null)
            && ($json['delta']['type'] ?? null) === 'text_delta') {
            return (string) ($json['delta']['text'] ?? '');
        }

        return null;
    }
}
