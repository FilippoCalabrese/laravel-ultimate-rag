<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Reranking;

use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Exceptions\ProviderException;
use Throwable;

/**
 * Retry decorator for reranker drivers (NFR-AF-01): retries transient failures
 * (429/5xx/connection) with exponential backoff + jitter, failing fast on
 * non-retryable errors. The sleeper is injectable for fast, deterministic tests.
 */
final class RetryingReranker implements Reranker
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly Reranker $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 100,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
    }

    public function rerank(string $query, array $hits, int $topK): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->inner->rerank($query, $hits, $topK);
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

    public function name(): string
    {
        return $this->inner->name();
    }
}
