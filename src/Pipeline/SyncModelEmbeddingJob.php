<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Pipeline;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Queued (re)index / removal of an embeddable model (FR-DX-05, FR-OR-01).
 *
 * Carries the model's morph identity rather than the instance, so a delete can
 * still be processed after the row is gone. Runs inside the model's tenant
 * context. If the model vanished between dispatch and handling, it is forgotten
 * from the index instead of synced.
 */
final class SyncModelEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $tenantId,
        public readonly bool $forget = false,
    ) {
        $this->onQueue((string) config('rag-engine.ingestion.queue', 'default'));
    }

    public function handle(ModelEmbedder $embedder, TenantContext $tenant): void
    {
        $tenant->runAs($this->tenantId, function () use ($embedder): void {
            if ($this->forget) {
                $embedder->forgetByIdentity($this->type, $this->id);

                return;
            }

            $class = Relation::getMorphedModel($this->type) ?? $this->type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                return;
            }

            $model = $class::query()->whereKey($this->id)->first();

            if ($model instanceof Model && $model instanceof Embeddable) {
                $embedder->sync($model);

                return;
            }

            // Row disappeared before we ran — make sure no stale vectors remain.
            $embedder->forgetByIdentity($this->type, $this->id);
        });
    }
}
