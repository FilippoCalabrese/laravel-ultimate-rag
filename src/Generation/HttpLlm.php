<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Base class for HTTP-backed LLM drivers (FR-GE-02). Subclasses describe a
 * provider's endpoint, request payload, auth and how to read the answer (and a
 * single streamed token). Shared here: the HTTP request, error handling and the
 * server-sent-events stream loop.
 */
abstract class HttpLlm implements Llm
{
    /**
     * @param  array<string, mixed>  $options  Provider-specific config (max_tokens, temperature, system, ...).
     */
    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly string $model,
        protected readonly ?string $apiKey = null,
        protected readonly string $baseUrl = '',
        protected readonly array $options = [],
    ) {}

    /** Provider name, used in error messages. */
    abstract protected function name(): string;

    /** Path appended to the base URL. */
    abstract protected function endpoint(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(string $prompt): array;

    abstract protected function extractText(mixed $json): string;

    abstract protected function applyAuth(PendingRequest $request): PendingRequest;

    /**
     * Parse one SSE line into a text fragment, or null if the line carries no
     * text (event lines, keep-alives, the terminal marker).
     */
    abstract protected function parseStreamLine(string $line): ?string;

    public function model(): string
    {
        return $this->model;
    }

    public function generate(string $prompt, array $options = []): string
    {
        $response = $this->request()->post($this->endpoint(), $this->payload($prompt));

        if ($response->failed()) {
            throw new RagException(sprintf(
                'LLM provider [%s] failed with status %d: %s',
                $this->name(),
                $response->status(),
                mb_substr($response->body(), 0, 500),
            ));
        }

        return trim($this->extractText($response->json()));
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->post($this->endpoint(), [...$this->payload($prompt), 'stream' => true]);

        if ($response->failed()) {
            throw new RagException(sprintf(
                'LLM provider [%s] streaming failed with status %d.',
                $this->name(),
                $response->status(),
            ));
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(2048);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);

                if ($line === '') {
                    continue;
                }

                $text = $this->parseStreamLine($line);
                if ($text !== null && $text !== '') {
                    yield $text;
                }
            }
        }

        $line = trim($buffer);
        if ($line !== '') {
            $text = $this->parseStreamLine($line);
            if ($text !== null && $text !== '') {
                yield $text;
            }
        }
    }

    protected function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->timeout((int) $this->option('timeout', 60))
            ->acceptJson()
            ->asJson();

        return $this->applyAuth($request);
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
