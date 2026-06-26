<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Token + cost accounting for a single provider operation.
 *
 * Supports FR-EM-07 / NFR-CT-01: every embedding/LLM/rerank call carries its
 * token and monetary cost so it can be aggregated per tenant.
 *
 * @implements Arrayable<string, mixed>
 */
final class Usage implements Arrayable
{
    public function __construct(
        public readonly int $tokens = 0,
        public readonly float $cost = 0.0,
        public readonly string $currency = 'EUR',
    ) {}

    public function plus(Usage $other): self
    {
        if ($other->tokens !== 0 || $other->cost !== 0.0) {
            if ($this->currency !== $other->currency) {
                throw new \InvalidArgumentException(
                    "Cannot add usage in [{$other->currency}] to usage in [{$this->currency}]."
                );
            }
        }

        return new self(
            $this->tokens + $other->tokens,
            $this->cost + $other->cost,
            $this->currency,
        );
    }

    public static function zero(string $currency = 'EUR'): self
    {
        return new self(0, 0.0, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'cost' => $this->cost,
            'currency' => $this->currency,
        ];
    }
}
