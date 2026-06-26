<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine;

use Sellinnate\RagEngine\Contracts\Embedder;
use Sellinnate\RagEngine\Contracts\KeyManagement;
use Sellinnate\RagEngine\Contracts\Llm;
use Sellinnate\RagEngine\Contracts\Reranker;
use Sellinnate\RagEngine\Contracts\Tokenizer;
use Sellinnate\RagEngine\Contracts\VectorStore;
use Sellinnate\RagEngine\Managers\EmbedderManager;
use Sellinnate\RagEngine\Managers\KmsManager;
use Sellinnate\RagEngine\Managers\LlmManager;
use Sellinnate\RagEngine\Managers\RerankerManager;
use Sellinnate\RagEngine\Managers\TokenizerManager;
use Sellinnate\RagEngine\Managers\VectorStoreManager;
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
    ) {}

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
