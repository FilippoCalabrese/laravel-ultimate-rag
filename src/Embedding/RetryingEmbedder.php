<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Exceptions\EmbeddingException;
use Throwable;

/**
 * Retry decorator (FR-EM-06, NFR-AF-01): retries failed embedding calls with
 * exponential backoff plus jitter. The sleeper is injectable so tests stay fast
 * and deterministic.
 */
final class RetryingEmbedder implements Embedder
{
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param  callable(int): void|null  $sleeper  Receives milliseconds to wait.
     */
    public function __construct(
        private readonly Embedder $inner,
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 100,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn (int $ms) => usleep($ms * 1000);
    }

    public function embed(array $texts): EmbeddingResponse
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->inner->embed($texts);
            } catch (Throwable $e) {
                $attempt++;

                // Don't waste attempts on non-retryable errors (4xx auth/bad-request,
                // malformed/partial responses). Connection errors and other Throwables
                // are treated as transient.
                if ($e instanceof EmbeddingException && ! $e->retryable) {
                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                // Exponential backoff with jitter (0..baseDelay).
                $delay = $this->baseDelayMs * (2 ** ($attempt - 1));
                $jitter = intdiv($delay, 2);
                ($this->sleeper)($delay + ($jitter > 0 ? random_int(0, $jitter) : 0));
            }
        }
    }

    public function embedOne(string $text): EmbeddingResponse
    {
        return $this->embed([$text]);
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }

    public function model(): string
    {
        return $this->inner->model();
    }
}
