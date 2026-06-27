<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Sellinnate\RagEngine\Console\Concerns\NormalizesInput;
use Sellinnate\RagEngine\Embedding\CachingEmbedder;
use Throwable;

/**
 * Clear cached embeddings (FR-DX-05). When the cache store supports tagging,
 * only the engine's `rag:emb` entries are flushed; otherwise the whole store is
 * cleared (and a warning is shown).
 */
final class ClearCacheCommand extends Command
{
    use NormalizesInput;

    protected $signature = 'rag:clear-cache {--tenant= : Only this tenant}';

    protected $description = 'Clear cached embeddings';

    public function handle(Cache $cache): int
    {
        $tenant = $this->stringOption('tenant');
        $tag = $tenant !== null
            ? CachingEmbedder::tenantTag($tenant)
            : 'rag:emb';

        try {
            $cache->tags($tag)->flush();
            $this->info("Cleared cached embeddings (tag: {$tag}).");

            return self::SUCCESS;
        } catch (Throwable) {
            // Non-taggable store: fall back to a full flush.
            $cache->clear();
            $this->warn('Cache store does not support tagging — flushed the entire cache.');

            return self::SUCCESS;
        }
    }
}
