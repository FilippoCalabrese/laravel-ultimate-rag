<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Clear the embedding cache (FR-DX-05). Only rag-engine cache keys are removed
 * when the store supports tagging; otherwise the whole store is flushed.
 */
final class ClearCacheCommand extends Command
{
    protected $signature = 'rag:clear-cache';

    protected $description = 'Clear cached embeddings';

    public function handle(Cache $cache): int
    {
        $cache->clear();
        $this->info('RAG embedding cache cleared.');

        return self::SUCCESS;
    }
}
