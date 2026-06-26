<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Concerns;

use Illuminate\Database\Eloquent\Model;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Pipeline\SyncModelEmbeddingJob;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * Makes an Eloquent model embeddable into the RAG engine (FR-DX-05).
 *
 * The model must implement {@see Embeddable} and declare what it embeds in
 * {@see Embeddable::toEmbeddable()}. This trait supplies the machinery:
 *
 * - a stable, morph-aware identity ({@see embeddableKey()}) used to group
 *   versions and to trace vectors back to the model;
 * - automatic (re)indexing on save and removal on delete, when
 *   `rag-engine.eloquent.auto_sync` is on — inline, or on a queue when
 *   `rag-engine.eloquent.queue` is set;
 * - manual {@see syncEmbedding()} / {@see forgetEmbedding()} entrypoints.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Embeddable
 */
trait HasEmbeddings
{
    public static function bootHasEmbeddings(): void
    {
        // Listeners are always registered; the `auto_sync` switch is evaluated
        // when an event fires, so toggling config at runtime takes effect.
        static::saved(static fn ($model) => $model->autoSyncEmbedding());
        static::deleted(static fn ($model) => $model->autoForgetEmbedding());

        // Re-index when a soft-deleted model is restored.
        if (method_exists(static::class, 'restored')) {
            static::restored(static fn ($model) => $model->autoSyncEmbedding());
        }
    }

    public function autoSyncEmbedding(): void
    {
        if (config('rag-engine.eloquent.auto_sync', true)) {
            $this->dispatchEmbeddingSync();
        }
    }

    public function autoForgetEmbedding(): void
    {
        if (config('rag-engine.eloquent.auto_sync', true)) {
            $this->dispatchEmbeddingForget();
        }
    }

    /**
     * Morph-aware type used in the vector payload (honours the morph map).
     */
    public function embeddableType(): string
    {
        return $this->getMorphClass();
    }

    public function embeddableId(): string
    {
        $key = $this->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Stable `type:id` identity for this model's indexed document.
     */
    public function embeddableKey(): string
    {
        return $this->embeddableType().':'.$this->embeddableId();
    }

    /**
     * Index (or re-index) this model now.
     *
     * @param  array<string, mixed>  $options
     */
    public function syncEmbedding(array $options = []): ?Document
    {
        return app(ModelEmbedder::class)->sync($this, $options);
    }

    /**
     * Remove this model from the index now.
     */
    public function forgetEmbedding(): void
    {
        app(ModelEmbedder::class)->forget($this);
    }

    /**
     * Sync inline, or dispatch a queued job when `eloquent.queue` is enabled.
     */
    public function dispatchEmbeddingSync(): void
    {
        if (config('rag-engine.eloquent.queue', false)) {
            SyncModelEmbeddingJob::dispatch($this->getMorphClass(), $this->embeddableId(), app(TenantContext::class)->id());

            return;
        }

        $this->syncEmbedding();
    }

    public function dispatchEmbeddingForget(): void
    {
        if (config('rag-engine.eloquent.queue', false)) {
            SyncModelEmbeddingJob::dispatch($this->getMorphClass(), $this->embeddableId(), app(TenantContext::class)->id(), forget: true);

            return;
        }

        $this->forgetEmbedding();
    }
}
