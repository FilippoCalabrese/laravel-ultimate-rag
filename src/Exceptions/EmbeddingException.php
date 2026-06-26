<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Exceptions;

/**
 * Raised by embedding providers. Carries whether the failure is worth retrying
 * (FR-EM-06): 429/5xx/connection errors are transient; 4xx auth/bad-request and
 * malformed responses are not.
 */
class EmbeddingException extends RagException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function fromStatus(string $provider, int $status): self
    {
        $retryable = $status === 429 || $status >= 500 || $status === 0;

        return new self(
            "Embedding provider [{$provider}] failed: HTTP {$status}.",
            $status,
            $retryable,
        );
    }
}
