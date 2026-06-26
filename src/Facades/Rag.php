<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Facades;

use Illuminate\Support\Facades\Facade;
use Sellinnate\RagEngine\RagEngine;

/**
 * @method static \Sellinnate\RagEngine\Contracts\Embedder embedder(?string $name = null)
 * @method static \Sellinnate\RagEngine\Contracts\VectorStore vectorStore(?string $name = null)
 * @method static \Sellinnate\RagEngine\Contracts\Reranker reranker(?string $name = null)
 * @method static \Sellinnate\RagEngine\Contracts\KeyManagement kms(?string $name = null)
 * @method static \Sellinnate\RagEngine\Contracts\Tokenizer tokenizer(?string $name = null)
 * @method static \Sellinnate\RagEngine\Contracts\Llm llm(?string $name = null)
 * @method static \Sellinnate\RagEngine\Security\EnvelopeEncrypter encrypter()
 * @method static \Sellinnate\RagEngine\Tenancy\TenantContext tenant()
 * @method static \Sellinnate\RagEngine\Ingestion\SourceFactory source()
 * @method static \Sellinnate\RagEngine\Ingestion\Ingestor ingestor()
 * @method static \Sellinnate\RagEngine\Parsing\ParserManager parser()
 * @method static \Sellinnate\RagEngine\Chunking\ChunkingService chunking()
 * @method static \Sellinnate\RagEngine\Embedding\EmbeddingService embedding()
 * @method static list<\Sellinnate\RagEngine\Data\TextChunk> chunk(\Sellinnate\RagEngine\Data\ParsedDocument $document, array<string, mixed> $options = [])
 * @method static \Sellinnate\RagEngine\Data\EmbeddingResponse embed(array<int, string> $texts, ?string $provider = null)
 * @method static \Sellinnate\RagEngine\Models\Document ingest(\Sellinnate\RagEngine\Ingestion\IngestionSource $source, array<string, mixed> $metadata = [])
 * @method static mixed forTenant(string $tenantId, callable $callback)
 *
 * @see RagEngine
 */
final class Rag extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RagEngine::class;
    }
}
