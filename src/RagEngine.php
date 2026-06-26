<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine;

use Sellinnate\RagEngine\Chunking\ChunkingService;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Data\EmbeddingResponse;
use Sellinnate\RagEngine\Data\ParsedDocument;
use Sellinnate\RagEngine\Data\TextChunk;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Embedding\EmbeddingService;
use Sellinnate\RagEngine\Generation\AskBuilder;
use Sellinnate\RagEngine\Generation\RagGenerator;
use Sellinnate\RagEngine\Indexing\Indexer;
use Sellinnate\RagEngine\Ingestion\IngestionSource;
use Sellinnate\RagEngine\Ingestion\Ingestor;
use Sellinnate\RagEngine\Ingestion\SourceFactory;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\TokenizerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Parsing\ParserManager;
use Sellinnate\RagEngine\Pipeline\IngestionPipeline;
use Sellinnate\RagEngine\Retrieval\Retriever;
use Sellinnate\RagEngine\Retrieval\SearchBuilder;
use Sellinnate\RagEngine\Security\EnvelopeEncrypter;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Public entrypoint of the engine (FR-DX-01). Exposes the resolved drivers and
 * cross-cutting services. Higher-level ingest/search/ask flows are layered on
 * top of these primitives in later phases.
 */
final class RagEngine
{
    public function __construct(
        private readonly EmbedderManager $embedders,
        private readonly VectorStoreManager $vectorStores,
        private readonly RerankerManager $rerankers,
        private readonly KmsManager $kms,
        private readonly TokenizerManager $tokenizers,
        private readonly LlmManager $llms,
        private readonly EnvelopeEncrypter $encrypter,
        private readonly TenantContext $tenant,
        private readonly SourceFactory $sources,
        private readonly Ingestor $ingestor,
        private readonly ParserManager $parsers,
        private readonly ChunkingService $chunking,
        private readonly EmbeddingService $embedding,
        private readonly Indexer $indexer,
        private readonly Retriever $retriever,
        private readonly IngestionPipeline $pipeline,
        private readonly RagGenerator $generator,
        private readonly ModelEmbedder $models,
    ) {}

    /**
     * Embed Eloquent models into the index, and trace vectors back to them
     * (FR-DX-05). Models implement {@see Embeddable}.
     */
    public function models(): ModelEmbedder
    {
        return $this->models;
    }

    /**
     * Run the full ingestion pipeline (parse → preprocess → chunk → embed →
     * index) on an already-ingested document (FR-OR).
     *
     * @param  array<string, mixed>  $options
     */
    public function process(Document $document, array $options = []): int
    {
        return $this->pipeline->process($document, $options);
    }

    /**
     * Ask a question over the corpus (optional generation layer, FR-GE).
     */
    public function ask(string $question): AskBuilder
    {
        return new AskBuilder($this->generator, $this->search($question));
    }

    public function indexer(): Indexer
    {
        return $this->indexer;
    }

    /**
     * Index a document's chunks into the vector store (FR-RT-08 plumbing).
     *
     * @param  list<TextChunk>  $chunks
     * @param  array<string, mixed>  $options
     */
    public function index(Document $document, array $chunks, array $options = []): int
    {
        return $this->indexer->index($document, $chunks, $options);
    }

    /**
     * Start a fluent retrieval query (FR-RT-08).
     */
    public function search(string $text): SearchBuilder
    {
        return new SearchBuilder($this->retriever, $text);
    }

    public function retriever(): Retriever
    {
        return $this->retriever;
    }

    public function chunking(): ChunkingService
    {
        return $this->chunking;
    }

    public function embedding(): EmbeddingService
    {
        return $this->embedding;
    }

    /**
     * Chunk a parsed document (FR-CH).
     *
     * @param  array<string, mixed>  $options
     * @return list<TextChunk>
     */
    public function chunk(ParsedDocument $document, array $options = []): array
    {
        return $this->chunking->chunk($document, $options);
    }

    /**
     * Embed texts with caching + cost tracking (FR-EM).
     *
     * @param  list<string>  $texts
     */
    public function embed(array $texts, ?string $provider = null): EmbeddingResponse
    {
        return $this->embedding->embed($texts, $provider);
    }

    public function source(): SourceFactory
    {
        return $this->sources;
    }

    public function ingestor(): Ingestor
    {
        return $this->ingestor;
    }

    public function parser(): ParserManager
    {
        return $this->parsers;
    }

    /**
     * Ingest a source into a {@see Document} (FR-DX-01).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ingest(IngestionSource $source, array $metadata = []): Document
    {
        return $this->ingestor->ingest($source, $metadata);
    }

    public function embedder(?string $name = null): Embedder
    {
        return $this->embedders->driver($name);
    }

    public function vectorStore(?string $name = null): VectorStore
    {
        return $this->vectorStores->driver($name);
    }

    public function reranker(?string $name = null): Reranker
    {
        return $this->rerankers->driver($name);
    }

    public function kms(?string $name = null): KeyManagement
    {
        return $this->kms->driver($name);
    }

    public function tokenizer(?string $name = null): Tokenizer
    {
        return $this->tokenizers->driver($name);
    }

    public function llm(?string $name = null): Llm
    {
        return $this->llms->driver($name);
    }

    public function encrypter(): EnvelopeEncrypter
    {
        return $this->encrypter;
    }

    public function tenant(): TenantContext
    {
        return $this->tenant;
    }

    /**
     * Convenience: run a closure scoped to a tenant (FR-MT-02).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function forTenant(string $tenantId, callable $callback): mixed
    {
        return $this->tenant->runAs($tenantId, $callback);
    }
}
