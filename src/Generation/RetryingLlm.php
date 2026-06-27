<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Generation;

use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Exceptions\ProviderException;
use Throwable;

/**
 * Retry decorator for LLM drivers (NFR-AF-01): retries transient failures
 * (429/5xx/connection) with exponential backoff + jitter, and fails fast on
 * non-retryable errors (401/400). The sleeper is injectable for fast tests.
 *
 * Streaming is delegated without retry — a mid-stream failure can't be replayed
 * safely once tokens have been emitted.
 */
final class RetryingLlm implements Llm
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly Llm $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 100,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
    }

    public function generate(string $prompt, array $options = []): string
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->inner->generate($prompt, $options);
            } catch (Throwable $e) {
                $attempt++;

                if ($e instanceof ProviderException && ! $e->retryable) {
                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delay = $this->baseDelayMs * (2 ** ($attempt - 1));
                $jitter = intdiv($delay, 2);
                ($this->sleeper)($delay + ($jitter > 0 ? random_int(0, $jitter) : 0));
            }
        }
    }

    public function stream(string $prompt, array $options = []): iterable
    {
        return $this->inner->stream($prompt, $options);
    }

    public function model(): string
    {
        return $this->inner->model();
    }
}
