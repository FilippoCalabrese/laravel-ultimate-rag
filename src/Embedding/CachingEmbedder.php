<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Embedding;

use Illuminate\Contracts\Cache\Repository as Cache;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\Usage;
use Sellinnate\RagEngine\Tenancy\TenantContext;
use Throwable;

/**
 * Caching decorator (FR-EM-05, NFR-PE-04). The cache key includes the tenant,
 * provider identity, model, dimensions and text hash, so:
 *  - identical text is never re-embedded (saves tokens/cost),
 *  - tenants never share cache entries (no cross-tenant inference channel),
 *  - entries can be selectively evicted on crypto-shredding via a tenant tag
 *    (when the cache store supports tagging; otherwise they expire by TTL).
 *
 * Only cache-missed texts hit the underlying provider; usage reflects only those.
 */
final class CachingEmbedder implements Embedder
{
    public function __construct(
        private readonly Embedder $inner,
        private readonly Cache $cache,
        private readonly int $ttl = 2592000, // 30 days
        private readonly string $prefix = 'rag:emb:',
        private readonly ?string $identity = null,
        private readonly ?TenantContext $tenant = null,
    ) {}

    /**
     * Cache tag used to evict a tenant's embeddings on crypto-shred.
     */
    public static function tenantTag(string $tenantId): string
    {
        return 'rag:emb:tenant:'.$tenantId;
    }

    public function embed(array $texts): EmbeddingResponse
    {
        /** @var array<int, list<float>> $vectors */
        $vectors = [];
        /** @var array<int, string> $missPositions */
        $missPositions = [];
        /** @var array<string, true> $uniqueMisses */
        $uniqueMisses = [];

        foreach ($texts as $i => $text) {
            $cached = $this->get($text);

            if (is_array($cached)) {
                $vectors[$i] = array_values(array_map('floatval', $cached));
            } else {
                $missPositions[$i] = $text;
                $uniqueMisses[$text] = true;
            }
        }

        $usage = Usage::zero();

        if ($uniqueMisses !== []) {
            $missTexts = array_keys($uniqueMisses);
            $response = $this->inner->embed($missTexts);
            $usage = $response->usage;

            $byText = [];
            foreach ($missTexts as $n => $text) {
                $vector = $response->vectorAt($n);
                $byText[$text] = $vector;
                $this->put($text, $vector);
            }

            foreach ($missPositions as $position => $text) {
                $vectors[$position] = $byText[$text];
            }
        }

        ksort($vectors);

        return new EmbeddingResponse(array_values($vectors), $this->model(), $this->dimensions(), $usage);
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

    private function get(string $text): mixed
    {
        $key = $this->key($text);

        try {
            return $this->cache->tags($this->tags())->get($key);
        } catch (Throwable) {
            return $this->cache->get($key);
        }
    }

    /**
     * @param  list<float>  $vector
     */
    private function put(string $text, array $vector): void
    {
        $key = $this->key($text);

        try {
            $this->cache->tags($this->tags())->put($key, $vector, $this->ttl);
        } catch (Throwable) {
            $this->cache->put($key, $vector, $this->ttl);
        }
    }

    /**
     * @return list<string>
     */
    private function tags(): array
    {
        return ['rag:emb', self::tenantTag($this->tenantId())];
    }

    private function key(string $text): string
    {
        $identity = $this->identity ?? $this->inner::class;

        return $this->prefix.hash('sha256', implode(':', [
            $this->tenantId(),
            $identity,
            $this->model(),
            (string) $this->dimensions(),
            $text,
        ]));
    }

    private function tenantId(): string
    {
        return $this->tenant?->id() ?? 'default';
    }
}
