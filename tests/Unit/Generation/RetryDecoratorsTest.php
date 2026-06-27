<?php

declare(strict_types=1);

use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Data\SearchHit;
use Sellinnate\RagEngine\Exceptions\ProviderException;
use Sellinnate\RagEngine\Generation\RetryingLlm;
use Sellinnate\RagEngine\Reranking\RetryingReranker;

/** An LLM that fails $failTimes with the given exception, then succeeds. */
function flakyLlm(int $failTimes, Throwable $error): Llm
{
    return new class($failTimes, $error) implements Llm
    {
        public int $calls = 0;

        public function __construct(private int $failTimes, private Throwable $error) {}

        public function generate(string $prompt, array $options = []): string
        {
            $this->calls++;
            if ($this->calls <= $this->failTimes) {
                throw $this->error;
            }

            return 'ok:'.$prompt;
        }

        public function stream(string $prompt, array $options = []): iterable
        {
            yield 'ok';
        }

        public function model(): string
        {
            return 'flaky';
        }
    };
}

it('RetryingLlm retries transient failures then succeeds', function () {
    $inner = flakyLlm(2, ProviderException::fromStatus('x', 503));
    $llm = new RetryingLlm($inner, maxAttempts: 3, sleeper: fn () => null);

    expect($llm->generate('hi'))->toBe('ok:hi')
        ->and($inner->calls)->toBe(3);
});

it('RetryingLlm fails fast on a non-retryable error', function () {
    $inner = flakyLlm(2, ProviderException::fromStatus('x', 401));
    $llm = new RetryingLlm($inner, maxAttempts: 5, sleeper: fn () => null);

    expect(fn () => $llm->generate('hi'))->toThrow(ProviderException::class)
        ->and($inner->calls)->toBe(1);
});

it('RetryingLlm gives up after maxAttempts', function () {
    $inner = flakyLlm(10, ProviderException::fromStatus('x', 500));
    $llm = new RetryingLlm($inner, maxAttempts: 3, sleeper: fn () => null);

    expect(fn () => $llm->generate('hi'))->toThrow(ProviderException::class);
    expect($inner->calls)->toBe(3);
});

it('RetryingReranker retries transient failures then succeeds', function () {
    $hits = [new SearchHit('a', 0.1, 'x')];
    $inner = new class($hits) implements Reranker
    {
        public int $calls = 0;

        /** @param list<SearchHit> $hits */
        public function __construct(private array $hits) {}

        public function rerank(string $query, array $hits, int $topK): array
        {
            $this->calls++;
            if ($this->calls < 2) {
                throw ProviderException::fromStatus('rr', 429);
            }

            return $this->hits;
        }

        public function name(): string
        {
            return 'flaky';
        }
    };

    $reranker = new RetryingReranker($inner, maxAttempts: 3, sleeper: fn () => null);

    expect($reranker->rerank('q', $hits, 1))->toHaveCount(1)
        ->and($inner->calls)->toBe(2)
        ->and($reranker->name())->toBe('flaky');
});
