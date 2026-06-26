<?php

declare(strict_types=1);

// Configuration for sellinnate/rag-engine.
//
// IMPORTANT: this file must stay `config:cache` safe (NFR-CP-02, decision 6.10):
// no closures, no objects — only scalars, arrays and env() calls.

return [

    /*
    |--------------------------------------------------------------------------
    | Default drivers
    |--------------------------------------------------------------------------
    | EU-resident by default (principle 5). Override per consumer.
    */
    'defaults' => [
        'embedder' => env('RAG_EMBEDDER', 'fake'),
        'vector_store' => env('RAG_VECTOR_STORE', 'memory'),
        'reranker' => env('RAG_RERANKER', 'null'),
        'kms' => env('RAG_KMS', 'local'),
        'tokenizer' => env('RAG_TOKENIZER', 'approximate'),
        'llm' => env('RAG_LLM', 'null'),
        'distance_metric' => env('RAG_DISTANCE_METRIC', 'cosine'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding providers (FR-EM)
    |--------------------------------------------------------------------------
    */
    'embedders' => [
        'fake' => [
            'driver' => 'fake',
            'dimensions' => 8,
            'model' => 'fake-embed-v1',
        ],
        'mistral' => [
            'driver' => 'mistral',
            'model' => env('RAG_MISTRAL_MODEL', 'mistral-embed'),
            'dimensions' => 1024,
            'api_key' => env('RAG_MISTRAL_API_KEY'),
            'base_url' => env('RAG_MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'eu_resident' => true,
        ],
        'ollama' => [
            'driver' => 'ollama',
            'model' => env('RAG_OLLAMA_MODEL', 'nomic-embed-text'),
            'dimensions' => 768,
            'base_url' => env('RAG_OLLAMA_BASE_URL', 'http://localhost:11434'),
            'eu_resident' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector stores (FR-VS) — Qdrant primary, pgvector + in-memory
    |--------------------------------------------------------------------------
    */
    'vector_stores' => [
        'memory' => [
            'driver' => 'memory',
        ],
        'qdrant' => [
            'driver' => 'qdrant',
            'host' => env('RAG_QDRANT_HOST', 'http://localhost:6333'),
            'api_key' => env('RAG_QDRANT_API_KEY'),
            'quantization' => env('RAG_QDRANT_QUANTIZATION'), // scalar|binary|null
        ],
        'pgvector' => [
            'driver' => 'pgvector',
            'connection' => env('RAG_PGVECTOR_CONNECTION', 'pgsql'),
            'table' => 'rag_vectors',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rerankers (FR-RR)
    |--------------------------------------------------------------------------
    */
    'rerankers' => [
        'null' => ['driver' => 'null'],
        'fake' => ['driver' => 'fake'],
    ],

    /*
    |--------------------------------------------------------------------------
    | KMS / BYOK (FR-SEC)
    |--------------------------------------------------------------------------
    */
    'kms' => [
        'local' => [
            'driver' => 'local',
            // Master secret used by the local dev/test KMS to wrap KEKs.
            'master_key' => env('RAG_KMS_MASTER_KEY'),
            'keystore' => env('RAG_KMS_KEYSTORE', storage_path('rag-engine/kms')),
        ],
        // aws/gcp/azure/vault drivers are registered by the KmsManager.
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokenizers (decision 6.11)
    |--------------------------------------------------------------------------
    */
    'tokenizers' => [
        'approximate' => [
            'driver' => 'approximate',
            // Avg characters per token, used by the heuristic counter.
            'chars_per_token' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM providers (optional generation layer, FR-GE)
    |--------------------------------------------------------------------------
    */
    'llms' => [
        'null' => ['driver' => 'null'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & encryption (FR-SEC)
    |--------------------------------------------------------------------------
    */
    'security' => [
        // Envelope-encrypt source content, chunk text and sensitive metadata.
        'encryption_enabled' => env('RAG_ENCRYPTION_ENABLED', true),
        // AEAD cipher used for content encryption with the DEK.
        'cipher' => 'aes-256-gcm',
        // PII redaction ON by default (FR-PP-03, NFR-CO-04).
        'pii_redaction_enabled' => env('RAG_PII_REDACTION', true),
        'pii_strategy' => env('RAG_PII_STRATEGY', 'mask'), // mask|tokenize
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-tenancy (FR-MT)
    |--------------------------------------------------------------------------
    */
    'tenancy' => [
        // namespace | schema | database
        'isolation' => env('RAG_TENANCY_ISOLATION', 'namespace'),
        'default_tenant' => env('RAG_DEFAULT_TENANT', 'default'),
        // Per-tenant quotas (FR-MT-04). null = unlimited.
        'quotas' => [
            'max_documents' => null,
            'max_corpus_bytes' => null,
            'max_embedding_tokens' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion & chunking defaults (FR-IN, FR-CH)
    |--------------------------------------------------------------------------
    */
    'ingestion' => [
        'max_upload_bytes' => env('RAG_MAX_UPLOAD_BYTES', 50 * 1024 * 1024),
        'queue' => env('RAG_QUEUE', 'default'),
        'sync' => env('RAG_INGESTION_SYNC', false),
    ],

    'chunking' => [
        'default_strategy' => env('RAG_CHUNK_STRATEGY', 'recursive'),
        'chunk_size' => 1000,
        'chunk_overlap' => 200,
        'max_tokens' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | Preprocessing pipeline (FR-PP-04) — ordered, activatable stages
    |--------------------------------------------------------------------------
    | PII redaction is ON by default (FR-PP-03). Order matters.
    */
    'preprocessing' => [
        'stages' => [
            'text-cleaner',
            'language-detector',
            'pii-redactor',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval defaults (FR-RT)
    |--------------------------------------------------------------------------
    */
    'retrieval' => [
        'top_k' => 10,
        'score_threshold' => null,
        'hybrid' => false,
        'mmr' => false,
        'mmr_lambda' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database table names
    |--------------------------------------------------------------------------
    */
    'tables' => [
        'documents' => 'rag_documents',
        'chunks' => 'rag_chunks',
        'embeddings' => 'rag_embeddings',
        'data_keys' => 'rag_data_keys',
        'ingestion_runs' => 'rag_ingestion_runs',
        'usage_records' => 'rag_usage_records',
        'audit_entries' => 'rag_audit_entries',
    ],
];
