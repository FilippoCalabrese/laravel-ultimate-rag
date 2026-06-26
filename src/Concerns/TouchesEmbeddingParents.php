<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Concerns;

use Illuminate\Database\Eloquent\Model;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Pipeline\SyncModelEmbeddingJob;
use Sellinnate\RagEngine\Tenancy\TenantContext;

/**
 * For a *child* model that is composed into a parent's embedding but is not a
 * searchable document in its own right (FR-DX-05).
 *
 * When the child changes (save/delete/restore), the parent embeddable is
 * re-indexed so the composed vector reflects the change. The child declares its
 * parents via {@see embeddingParents()}.
 *
 * Use {@see HasEmbeddings} instead when the model should itself be an indexed
 * document. A model that is both a root document *and* nested in another can use
 * both traits.
 *
 * @phpstan-require-extends Model
 */
trait TouchesEmbeddingParents
{
    public static function bootTouchesEmbeddingParents(): void
    {
        // Always listen; gate on `auto_sync` at fire-time (see HasEmbeddings).
        static::saved(static fn ($model) => $model->autoResyncEmbeddingParents());
        static::deleted(static fn ($model) => $model->autoResyncEmbeddingParents());

        if (method_exists(static::class, 'restored')) {
            static::restored(static fn ($model) => $model->autoResyncEmbeddingParents());
        }
    }

    public function autoResyncEmbeddingParents(): void
    {
        if (config('rag-engine.eloquent.auto_sync', true)) {
            $this->resyncEmbeddingParents();
        }
    }

    /**
     * The embeddable parent models to re-index when this child changes.
     *
     * @return iterable<Embeddable>
     */
    abstract public function embeddingParents(): iterable;

    public function resyncEmbeddingParents(): void
    {
        $queue = (bool) config('rag-engine.eloquent.queue', false);

        foreach ($this->embeddingParents() as $parent) {
            if (! $parent instanceof Model || ! $parent instanceof Embeddable) {
                continue;
            }

            if ($queue) {
                $parentKey = $parent->getKey();
                SyncModelEmbeddingJob::dispatch(
                    $parent->getMorphClass(),
                    is_scalar($parentKey) ? (string) $parentKey : '',
                    app(TenantContext::class)->id(),
                );

                continue;
            }

            app(ModelEmbedder::class)->sync($parent);
        }
    }
}
