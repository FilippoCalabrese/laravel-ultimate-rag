<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * An HTTP provider (LLM, reranker) returned an error. Carries the status code
 * and whether the failure is transient, so retry decorators can back off on
 * 429/5xx/connection errors but fail fast on 4xx (auth/bad request).
 */
class ProviderException extends RagException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function fromStatus(string $provider, int $status, string $body = ''): self
    {
        $retryable = $status === 429 || $status >= 500 || $status === 0;

        $message = "Provider [{$provider}] failed with status {$status}";
        if ($body !== '') {
            $message .= ': '.mb_substr($body, 0, 300);
        }

        return new self($message, $status, $retryable);
    }
}
