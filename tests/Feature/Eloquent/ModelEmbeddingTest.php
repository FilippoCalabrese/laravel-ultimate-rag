<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Sellinnate\RagEngine\Concerns\HasEmbeddings;
use Sellinnate\RagEngine\Concerns\TouchesEmbeddingParents;
use Sellinnate\RagEngine\Contracts\Embeddable;
use Sellinnate\RagEngine\Eloquent\EmbeddableDefinition;
use Sellinnate\RagEngine\Eloquent\ModelEmbedder;
use Sellinnate\RagEngine\Exceptions\RagException;
use Sellinnate\RagEngine\Facades\Rag;
use Sellinnate\RagEngine\Models\Document;
use Sellinnate\RagEngine\Pipeline\SyncModelEmbeddingJob;

beforeEach(function () {
    // App-side tables for the test models.
    foreach (['blog_authors', 'blog_posts', 'blog_comments'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('blog_authors', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->text('bio')->nullable();
    });
    Schema::create('blog_posts', function ($t) {
        $t->increments('id');
        $t->unsignedInteger('author_id')->nullable();
        $t->string('title');
        $t->text('body');
    });
    Schema::create('blog_comments', function ($t) {
        $t->increments('id');
        $t->unsignedInteger('post_id');
        $t->text('body');
    });

    // Manual control by default; individual tests opt into auto-sync.
    config()->set('rag-engine.eloquent.auto_sync', false);
    config()->set('rag-engine.eloquent.queue', false);
});

function makePost(string $title = 'Solar Power', string $body = 'Photovoltaic panels convert sunlight into electricity.'): BlogPost
{
    return BlogPost::create(['title' => $title, 'body' => $body]);
}

it('indexes a model and makes it searchable (FR-DX-05)', function () {
    $post = makePost();

    $document = $post->syncEmbedding();

    expect($document)->toBeInstanceOf(Document::class)
        ->and($document->status)->toBe('indexed')
        ->and($document->metadata['embeddable_type'])->toBe(BlogPost::class)
        ->and($document->metadata['embeddable_id'])->toBe((string) $post->id);

    $hits = Rag::search('photovoltaic sunlight electricity')->topK(5)->get();
    expect($hits)->not->toBeEmpty();
});

it('traces a retrieved vector back to its originating model', function () {
    $post = makePost(body: 'A uniquely identifiable marker phrase xyzzy-trace.');
    $post->syncEmbedding();

    $hits = Rag::search('xyzzy-trace marker')->topK(5)->get();

    // The hit metadata alone (no extra query) identifies the model.
    $hit = $hits[0];
    expect($hit->metadata['embeddable_type'])->toBe(BlogPost::class)
        ->and($hit->metadata['embeddable_id'])->toBe((string) $post->id);

    $resolved = app(ModelEmbedder::class)->resolve($hit);
    expect($resolved)->toBeInstanceOf(BlogPost::class)
        ->and($resolved->is($post))->toBeTrue();
});

it('recursively embeds related models (author + comments)', function () {
    $author = BlogAuthor::create(['name' => 'Ada Lovelace', 'bio' => 'Pioneer of computing.']);
    $post = BlogPost::create(['author_id' => $author->id, 'title' => 'Engines', 'body' => 'On analytical engines.']);
    BlogComment::create(['post_id' => $post->id, 'body' => 'Fascinating perspective on looms.']);

    $document = $post->load(['author', 'comments'])->syncEmbedding();
    $content = Rag::ingestor()->content($document);

    expect($content)->toContain('Ada Lovelace')        // composed author
        ->and($content)->toContain('Fascinating perspective on looms.') // composed comment
        ->and($document->metadata['included_keys'])->toContain(BlogAuthor::class.':'.$author->id);

    // A query matching the related content still resolves to the parent post.
    $hits = Rag::search('looms fascinating perspective')->topK(5)->get();
    $resolved = app(ModelEmbedder::class)->resolve($hits[0]);
    expect($resolved)->toBeInstanceOf(BlogPost::class)
        ->and($resolved->is($post))->toBeTrue();
});

it('re-indexes on a field change without leaving orphan vectors', function () {
    $post = makePost(title: 'Solar', body: 'About solar energy.');
    $post->syncEmbedding();

    expect(Rag::vectorStore()->count('documents'))->toBe(1)
        ->and(Document::query()->where('metadata->document_key', $post->embeddableKey())->count())->toBe(1);

    // Change a field and re-sync.
    $post->update(['title' => 'Nuclear', 'body' => 'About nuclear fission energy.']);
    $post->syncEmbedding();

    // Exactly one live document, exactly one vector — the old generation is gone.
    expect(Rag::vectorStore()->count('documents'))->toBe(1)
        ->and(Document::query()->where('metadata->document_key', $post->embeddableKey())->count())->toBe(1);

    $live = Document::query()->where('metadata->document_key', $post->embeddableKey())->firstOrFail();
    expect(Rag::ingestor()->content($live))->toContain('nuclear fission')
        ->and(Rag::ingestor()->content($live))->not->toContain('solar energy');
});

it('removes a model from the index on forget/delete', function () {
    $post = makePost();
    $post->syncEmbedding();
    expect(Rag::vectorStore()->count('documents'))->toBe(1);

    $post->forgetEmbedding();

    expect(Rag::vectorStore()->count('documents'))->toBe(0)
        ->and(Document::query()->where('metadata->document_key', $post->embeddableKey())->count())->toBe(0);
});

it('auto-syncs on save and removes on delete when enabled', function () {
    config()->set('rag-engine.eloquent.auto_sync', true);

    $post = makePost();   // create => saved event => auto sync
    expect(Rag::vectorStore()->count('documents'))->toBe(1);

    $post->delete();      // deleted event => auto forget
    expect(Rag::vectorStore()->count('documents'))->toBe(0);
});

it('re-syncs the parent when a child (comment) changes', function () {
    config()->set('rag-engine.eloquent.auto_sync', true);

    $post = BlogPost::create(['title' => 'Hosting', 'body' => 'Cloud hosting overview.']);
    // Post auto-synced on create; now add a comment with distinctive text.
    BlogComment::create(['post_id' => $post->id, 'body' => 'Mention of kubernetes orchestration.']);

    // The comment's save re-synced the parent post; its content now includes it.
    $document = Document::query()->where('metadata->document_key', $post->embeddableKey())->firstOrFail();
    expect(Rag::ingestor()->content($document))->toContain('kubernetes orchestration');
});

it('dispatches a queued job instead of syncing inline when queue is enabled', function () {
    Bus::fake();
    config()->set('rag-engine.eloquent.auto_sync', true);
    config()->set('rag-engine.eloquent.queue', true);

    $post = makePost();

    Bus::assertDispatched(SyncModelEmbeddingJob::class, fn (SyncModelEmbeddingJob $job) => $job->type === BlogPost::class
        && $job->id === (string) $post->id
        && $job->forget === false);
});

it('dispatches a queued job for the parent when a child changes', function () {
    $post = makePost(); // auto_sync off: no job yet

    config()->set('rag-engine.eloquent.auto_sync', true);
    config()->set('rag-engine.eloquent.queue', true);
    Bus::fake();

    BlogComment::create(['post_id' => $post->id, 'body' => 'A new child comment.']);

    Bus::assertDispatched(SyncModelEmbeddingJob::class, fn (SyncModelEmbeddingJob $job) => $job->type === BlogPost::class
        && $job->id === (string) $post->id
        && $job->forget === false);
});

it('dispatches a queued forget job when a model is deleted', function () {
    config()->set('rag-engine.eloquent.auto_sync', true);
    config()->set('rag-engine.eloquent.queue', true);
    Bus::fake();

    $post = makePost();
    $post->delete();

    Bus::assertDispatched(SyncModelEmbeddingJob::class, fn (SyncModelEmbeddingJob $job) => $job->id === (string) $post->id && $job->forget === true);
});

it('the queued job ignores an unresolvable morph type', function () {
    (new SyncModelEmbeddingJob('no-such-morph-type', '1', 'default'))
        ->handle(app(ModelEmbedder::class), Rag::tenant());

    // No model resolved, nothing indexed, and crucially no exception thrown.
    expect(Rag::vectorStore()->namespaceExists('documents'))->toBeFalse();
});

it('the queued job indexes the model when handled', function () {
    $post = makePost();

    (new SyncModelEmbeddingJob(BlogPost::class, (string) $post->id, $post->getMorphClass() === BlogPost::class ? 'default' : 'default'))
        ->handle(app(ModelEmbedder::class), Rag::tenant());

    expect(Rag::vectorStore()->count('documents'))->toBe(1);
});

it('the queued job forgets a model that no longer exists', function () {
    $post = makePost();
    $post->syncEmbedding();
    $key = $post->embeddableKey();
    $id = (string) $post->id;
    $post->delete();   // auto_sync off, so the index still holds it

    expect(Rag::vectorStore()->count('documents'))->toBe(1);

    (new SyncModelEmbeddingJob(BlogPost::class, $id, 'default'))->handle(app(ModelEmbedder::class), Rag::tenant());

    expect(Rag::vectorStore()->count('documents'))->toBe(0)
        ->and(Document::query()->where('metadata->document_key', $key)->count())->toBe(0);
});

it('rejects a model that does not implement the Embeddable contract', function () {
    $model = new NotEmbeddableModel(['title' => 'x']);

    app(ModelEmbedder::class)->sync($model);
})->throws(RagException::class, 'Embeddable');

/*
|--------------------------------------------------------------------------
| Test models
|--------------------------------------------------------------------------
*/

class BlogAuthor extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'blog_authors';

    protected $guarded = [];

    public $timestamps = false;

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Author', $this->name)
            ->add('Bio', $this->bio);
    }
}

class BlogPost extends Model implements Embeddable
{
    use HasEmbeddings;

    protected $table = 'blog_posts';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<BlogAuthor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(BlogAuthor::class, 'author_id');
    }

    /** @return HasMany<BlogComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(BlogComment::class, 'post_id');
    }

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()
            ->add('Title', $this->title)
            ->add('Body', $this->body)
            ->include($this->author, 'author')
            ->includeMany($this->comments, 'comments')
            ->metadata(['kind' => 'post']);
    }
}

class BlogComment extends Model implements Embeddable
{
    use TouchesEmbeddingParents;

    protected $table = 'blog_comments';

    protected $guarded = [];

    public $timestamps = false;

    /** @return BelongsTo<BlogPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'post_id');
    }

    public function toEmbeddable(): EmbeddableDefinition
    {
        return EmbeddableDefinition::make()->add('Comment', $this->body);
    }

    public function embeddingParents(): iterable
    {
        $post = $this->post;

        return $post instanceof BlogPost ? [$post] : [];
    }
}

class NotEmbeddableModel extends Model
{
    protected $table = 'blog_posts';

    protected $guarded = [];

    public $timestamps = false;
}
