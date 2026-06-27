<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Pipeline;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sellinnate\RagEngine\Events\IngestionFailed;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Tenancy\TenantContext;
use Throwable;

/**
 * Queued document-processing job (FR-OR-01). Batchable so a corpus can be
 * ingested as one `Bus::batch`. Retries with backoff (NFR-AF-01); a terminal
 * failure routes to {@see failed()} for dead-letter handling (FR-OR-05).
 */
final class ProcessDocumentJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly array $options = [],
    ) {
        $this->onQueue((string) config('rag-engine.ingestion.queue', 'default'));
    }

    public function handle(IngestionPipeline $pipeline, TenantContext $tenant): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tenant->runAs($this->tenantId, function () use ($pipeline): void {
            $document = Document::query()->where('id', $this->documentId)->firstOrFail();
            $pipeline->process($document, $this->options);
        });
    }

    public function failed(Throwable $exception): void
    {
        // Dead-letter: mark the document failed and emit the lifecycle event.
        $document = Document::query()->find($this->documentId);

        if ($document !== null) {
            $document->forceFill(['status' => 'failed'])->save();
        }

        event(new IngestionFailed($this->documentId, $this->tenantId, $exception->getMessage()));
    }
}
